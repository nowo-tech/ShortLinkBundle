<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit\Application;

use ArrayObject;
use DateTimeImmutable;
use Nowo\ShortLinkBundle\Application\CodeGenerator;
use Nowo\ShortLinkBundle\Application\Default\ConfigDenylist;
use Nowo\ShortLinkBundle\Application\Default\ConfigQuota;
use Nowo\ShortLinkBundle\Application\Default\ConfigReservedCodes;
use Nowo\ShortLinkBundle\Application\Guard\DenylistGuard;
use Nowo\ShortLinkBundle\Application\ManagerSettings;
use Nowo\ShortLinkBundle\Application\ShortLinkManager;
use Nowo\ShortLinkBundle\Application\ShortUrlBuilderInterface;
use Nowo\ShortLinkBundle\Application\Store\StoredLink;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkDeletedEvent;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkUpdatedEvent;
use Nowo\ShortLinkBundle\Domain\Exception\CodeAttemptsExceededException;
use Nowo\ShortLinkBundle\Domain\Exception\ShortLinkNotFoundException;
use Nowo\ShortLinkBundle\Domain\Model\ActorRef;
use Nowo\ShortLinkBundle\Domain\Model\CreateShortLink;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\LinkSource;
use Nowo\ShortLinkBundle\Domain\Model\LinkTitle;
use Nowo\ShortLinkBundle\Domain\Model\ShortCode;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkListQuery;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkPermission;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkState;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Model\UpdateShortLink;
use Nowo\ShortLinkBundle\Domain\Port\CustomCodeLimiterInterface;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkAccessInterface;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkIdGeneratorInterface;
use Nowo\ShortLinkBundle\Infrastructure\Lock\NoTenantLock;
use Nowo\ShortLinkBundle\Tests\Fixtures\MemoryLogger;
use Nowo\ShortLinkBundle\Tests\Fixtures\RacingStore;
use Nowo\ShortLinkBundle\Tests\Fixtures\ScriptedStore;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

use function array_map;
use function sprintf;
use function strlen;

/**
 * Manager paths that need a scripted store: code collisions growing the length, access enforcement switched off.
 */
final class ShortLinkManagerTest extends TestCase
{
    public function testCollisionsGrowTheLengthUpToSixteenAndAreLogged(): void
    {
        $store  = new ScriptedStore(collisions: 12);
        $logger = new MemoryLogger();
        $view   = $this->manager($store, new ManagerSettings(randomLength: 14, maxAttempts: 3), $logger)->create(TenantKey::default(), new CreateShortLink('https://example.com', system: true));

        self::assertSame(16, strlen($view->code->value));
        self::assertCount(2, $logger->records, '14 → 15 → 16, then it stays at 16');
        self::assertSame('default', $logger->records[0]['context']['tenant']);
        self::assertArrayNotHasKey('code', $logger->records[0]['context']);
        self::assertSame(13, $store->probes);
    }

    public function testAccessIsNotCheckedWhenEnforcementIsOff(): void
    {
        $store   = new ScriptedStore();
        $manager = $this->manager($store, new ManagerSettings(enforceAccess: false));
        $view    = $manager->create(TenantKey::default(), new CreateShortLink('https://example.com'));

        self::assertSame('https://example.com', $view->target->toString());
        self::assertSame([], $manager->list(TenantKey::default(), new ShortLinkListQuery())->items);
        self::assertSame('https://s.example/' . $view->code->value, $manager->shortUrl($view->code));
    }

    public function testACustomCodeAttemptIsTakenBeforeTheLookupAndNeverForSystemCommands(): void
    {
        $log   = new ArrayObject();
        $store = new class($log) extends ScriptedStore {
            /** @param ArrayObject<int, string> $log */
            public function __construct(private readonly ArrayObject $log)
            {
                parent::__construct();
            }

            public function codeExists(string $code): bool
            {
                $this->log->append('lookup ' . $code);

                return false;
            }
        };
        $limiter = new class($log) implements CustomCodeLimiterInterface {
            public int $left = 1;

            /** @param ArrayObject<int, string> $log */
            public function __construct(private readonly ArrayObject $log)
            {
            }

            public function attempt(TenantKey $tenant, ?ActorRef $actor): bool
            {
                $this->log->append('attempt ' . $tenant->value . ' ' . ($actor instanceof ActorRef ? $actor->value : '-'));

                return $this->left-- > 0;
            }
        };
        $manager = $this->manager($store, new ManagerSettings(enforceAccess: false), null, $limiter);
        $manager->create(TenantKey::default(), new CreateShortLink('https://example.com', customCode: 'first-code'));
        self::assertSame(['attempt default -', 'lookup first-code'], $log->getArrayCopy(), 'the attempt is consumed before the lookup, so concurrent requests cannot all pass a peek');

        $log->exchangeArray([]);
        try {
            $manager->create(TenantKey::default(), new CreateShortLink('https://example.com', customCode: 'second-code'));
            self::fail('Over the limit.');
        } catch (CodeAttemptsExceededException) {
            self::assertSame(['attempt default -'], $log->getArrayCopy(), 'over the limit nothing is looked up');
        }

        $log->exchangeArray([]);
        $manager->create(TenantKey::default(), new CreateShortLink('https://example.com', customCode: 'system-code', system: true));
        self::assertSame(['lookup system-code'], $log->getArrayCopy(), 'system commands are never limited');
    }

    public function testAnEditAppliesToTheRowReadUnderItsLockAndNeverRevertsAConcurrentChange(): void
    {
        $before = $this->link(true, 'Old');
        $fresh  = $before->withActive(false, 'manual', new DateTimeImmutable('2026-11-12 10:00:01'), null);
        $store  = new RacingStore($before, $fresh);
        /** @var ArrayObject<int, object> $events */
        /** @var ArrayObject<int, object> $events */
        $events = new ArrayObject();

        $view = $this->manager($store, new ManagerSettings(enforceAccess: false), null, null, $events)->update(TenantKey::default(), $before->id, new UpdateShortLink('https://example.com/new', 'Old'));

        self::assertCount(1, $store->updated);
        self::assertFalse($store->updated[0]->active, 'the disable committed in between is kept');
        self::assertSame('https://example.com/new', $store->updated[0]->target->toString());
        self::assertSame(ShortLinkState::Disabled, $view->state);
        self::assertCount(1, $events);
        $event = $events[0];
        self::assertInstanceOf(ShortLinkUpdatedEvent::class, $event);
        self::assertSame(['target'], $event->changedFields);
        self::assertSame([null], $store->locks, 'an edit takes the row lock, not the tenant lock');
    }

    public function testAnEditAlreadyAppliedOrOfADeletedLinkWritesNothing(): void
    {
        $before = $this->link(true, 'Old');
        $fresh  = $before->withEdit(TargetUrl::fromString('https://example.com/new'), LinkTitle::fromString('Old'), null, new DateTimeImmutable('2026-11-12 10:00:01'), null);
        $store  = new RacingStore($before, $fresh);
        /** @var ArrayObject<int, object> $events */
        /** @var ArrayObject<int, object> $events */
        $events = new ArrayObject();
        $view   = $this->manager($store, new ManagerSettings(enforceAccess: false), null, null, $events)->update(TenantKey::default(), $before->id, new UpdateShortLink('https://example.com/new', 'Old'));
        self::assertSame([], $store->updated, 'the same edit committed in between');
        self::assertCount(0, $events);
        self::assertSame('https://example.com/new', $view->target->toString());

        $store = new RacingStore($before);
        $this->expectException(ShortLinkNotFoundException::class);
        $this->manager($store, new ManagerSettings(enforceAccess: false))->update(TenantKey::default(), $before->id, new UpdateShortLink('https://example.com/new', 'Old'));
    }

    public function testADeleteOrStateChangeRacedByAnotherOneWritesAndAnnouncesNothing(): void
    {
        $before = $this->link(true, 'Old');
        /** @var ArrayObject<int, object> $events */
        /** @var ArrayObject<int, object> $events */
        $events = new ArrayObject();

        $store = new RacingStore($before);
        $this->manager($store, new ManagerSettings(enforceAccess: false), null, null, $events)->delete(TenantKey::default(), $before->id);
        $this->manager($store, new ManagerSettings(enforceAccess: false), null, null, $events)->disable(TenantKey::default(), $before->id);
        $store = new RacingStore($before, $before->withActive(false, 'manual', new DateTimeImmutable('2026-11-12 10:00:01'), null));
        $this->manager($store, new ManagerSettings(enforceAccess: false), null, null, $events)->disable(TenantKey::default(), $before->id);
        self::assertSame([], $store->updated);
        self::assertCount(0, $events);

        $edited = $before->withEdit(TargetUrl::fromString('https://example.com/edited'), null, null, new DateTimeImmutable('2026-11-12 10:00:01'), null);
        $store  = new RacingStore($before, $edited);
        $this->manager($store, new ManagerSettings(enforceAccess: false), null, null, $events)->delete(TenantKey::default(), $before->id);
        self::assertCount(1, $store->updated);
        self::assertSame('https://example.com/edited', $store->updated[0]->target->toString(), 'the tombstone keeps the concurrent edit');
        self::assertNotNull($store->updated[0]->deletedAt);
        self::assertInstanceOf(ShortLinkDeletedEvent::class, $events[0]);
    }

    public function testOnlyCountedCreatesTakeTheTenantDatabaseLock(): void
    {
        $tenant  = TenantKey::default();
        $store   = new RacingStore();
        $manager = $this->manager($store, new ManagerSettings(enforceAccess: false));

        $manager->create($tenant, new CreateShortLink('https://example.com'));
        $manager->create($tenant, new CreateShortLink('https://example.com', kind: LinkKind::email()));
        $manager->createMany($tenant, [new CreateShortLink('https://example.com', kind: LinkKind::email(), source: new LinkSource('send-1', null, 1))]);
        $manager->createMany($tenant, [new CreateShortLink('https://example.com', kind: LinkKind::email())]);
        $manager->createMany($tenant, [new CreateShortLink('https://example.com')]);

        self::assertEquals([$tenant, null, null, null, $tenant], $store->locks, 'counted kind, uncounted, sourced batch (the unique key decides, UK-SL-2), unsourced batch, counted batch');
    }

    /**
     * Audit UK-SL-2: the live-source unique key refused the insert because another connection committed the same source
     * first; the store answers that link, and create() / createMany() return it and announce nothing.
     */
    public function testALostSourceRaceReturnsTheWinnerAndAnnouncesNothing(): void
    {
        $tenant        = TenantKey::default();
        $at            = new DateTimeImmutable('2026-11-12 10:00:00');
        $store         = new RacingStore();
        $store->winner = new StoredLink(ShortLinkId::fromString('0190a6d0-0000-7000-8000-000000000077'), $tenant, ShortCode::random('win1234'), TargetUrl::fromString('https://example.com/won'), null, LinkKind::email(), new LinkSource('send-1', null, 1), null, true, null, null, $at, $at, null, null, null);
        $events        = new ArrayObject();
        $manager       = $this->manager($store, new ManagerSettings(enforceAccess: false), null, null, $events);
        $command       = new CreateShortLink('https://example.com/mine', kind: LinkKind::email(), source: new LinkSource('send-1', null, 1));

        self::assertSame('win1234', $manager->create($tenant, $command)->code->value);
        $views = $manager->createMany($tenant, [$command, $command]);
        self::assertSame(['win1234', 'win1234'], [$views[0]->code->value, $views[1]->code->value]);
        self::assertCount(0, $events, 'the winner announced its link');
    }

    /**
     * Audit UY-2: a batch inserts its sourced links in a fixed order (kind, ref in byte order, position), whatever the
     * order of the commands, so two batches with the same sources never lock crosswise; unsourced links come last.
     */
    public function testABatchInsertsInAFixedLockOrder(): void
    {
        $store   = new RacingStore();
        $manager = $this->manager($store, new ManagerSettings(enforceAccess: false));
        $email   = static fn (string $ref, ?int $position): CreateShortLink => new CreateShortLink('https://example.com/' . $ref, kind: LinkKind::email(), source: new LinkSource($ref, null, $position));

        $views = $manager->createMany(TenantKey::default(), [
            $email('s2', 1),
            new CreateShortLink('https://example.com/plain', kind: LinkKind::email()),
            $email('s10', 0),
            $email('s1', 2),
            new CreateShortLink('https://example.com/manual', source: new LinkSource('s0', null, 0)),
            $email('s1', null),
            $email('s1', 0),
        ]);

        $order = array_map(static fn (StoredLink $link): string => $link->kind->value . ':' . ($link->source instanceof LinkSource ? $link->source->ref . '@' . ($link->source->position ?? 'none') : '-'), $store->inserted);
        self::assertSame(['email:s1@none', 'email:s1@0', 'email:s1@2', 'email:s10@0', 'email:s2@1', 'manual:s0@0', 'email:-'], $order);
        self::assertSame('https://example.com/s2', $views[0]->target->toString(), 'the answer keeps the command order');
        self::assertSame('https://example.com/s1', $views[6]->target->toString());
    }

    private function link(bool $active, ?string $title): StoredLink
    {
        $at = new DateTimeImmutable('2026-11-12 10:00:00');

        return new StoredLink(ShortLinkId::fromString('0190a6d0-0000-7000-8000-000000000099'), TenantKey::default(), ShortCode::random('abc1234'), TargetUrl::fromString('https://example.com/old'), LinkTitle::fromString($title), LinkKind::manual(), null, null, $active, null, null, $at, $at, null, null, null);
    }

    /**
     * @param ArrayObject<int, object>|null $recorded
     */
    private function manager(ScriptedStore $store, ManagerSettings $settings, ?MemoryLogger $logger = null, ?CustomCodeLimiterInterface $limiter = null, ?ArrayObject $recorded = null): ShortLinkManager
    {
        $deny = new class implements ShortLinkAccessInterface {
            public function isGranted(ShortLinkPermission $permission, TenantKey $tenant): bool
            {
                return false;
            }

            public function currentActor(): ?ActorRef
            {
                return null;
            }
        };
        $ids = new class implements ShortLinkIdGeneratorInterface {
            private int $next = 0;

            public function generate(): ShortLinkId
            {
                return ShortLinkId::fromString(sprintf('0190a6d0-0000-7000-8000-%012d', ++$this->next));
            }
        };
        $urls = new class implements ShortUrlBuilderInterface {
            public function build(ShortCode $code): string
            {
                return 'https://s.example/' . $code->value;
            }
        };
        $events = new class($recorded ?? new ArrayObject()) implements EventDispatcherInterface {
            /** @param ArrayObject<int, object> $recorded */
            public function __construct(private readonly ArrayObject $recorded)
            {
            }

            public function dispatch(object $event): object
            {
                $this->recorded->append($event);

                return $event;
            }
        };

        return new ShortLinkManager($store, new CodeGenerator(new ConfigReservedCodes(), null), new ConfigReservedCodes(), new DenylistGuard(new ConfigDenylist()), $deny, new ConfigQuota(null), new NoTenantLock(), $ids, $urls, $events, $settings, null, $logger, $limiter);
    }
}
