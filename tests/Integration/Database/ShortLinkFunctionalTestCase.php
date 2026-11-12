<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration\Database;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Nowo\ShortLinkBundle\Application\Store\ShortLinkStoreInterface;
use Nowo\ShortLinkBundle\Application\Store\StoredLink;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkCreatedEvent;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkDeletedEvent;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkStateChangedEvent;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkTargetRefusedEvent;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkUpdatedEvent;
use Nowo\ShortLinkBundle\Domain\Exception\AccessDeniedException;
use Nowo\ShortLinkBundle\Domain\Exception\CodeReservedException;
use Nowo\ShortLinkBundle\Domain\Exception\CodeTakenException;
use Nowo\ShortLinkBundle\Domain\Exception\InvalidShortLinkArgumentException;
use Nowo\ShortLinkBundle\Domain\Exception\QuotaExceededException;
use Nowo\ShortLinkBundle\Domain\Exception\ShortLinkNotFoundException;
use Nowo\ShortLinkBundle\Domain\Exception\TargetRefusedException;
use Nowo\ShortLinkBundle\Domain\Model\ActorRef;
use Nowo\ShortLinkBundle\Domain\Model\CreateShortLink;
use Nowo\ShortLinkBundle\Domain\Model\Expiry;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\LinkMetadata;
use Nowo\ShortLinkBundle\Domain\Model\LinkSource;
use Nowo\ShortLinkBundle\Domain\Model\LinkTitle;
use Nowo\ShortLinkBundle\Domain\Model\ShortCode;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkListQuery;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkState;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkView;
use Nowo\ShortLinkBundle\Domain\Model\TargetRefusal;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Model\UpdateShortLink;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkManagerInterface;
use Nowo\ShortLinkBundle\Tests\Fixtures\RecordingListener;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\Host;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\TestKernel;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function array_map;
use function gc_collect_cycles;
use function memory_get_usage;
use function preg_match;
use function strlen;

/**
 * S1 functional suite, run once per host kind on the configured database (SQLite locally and in the coverage job;
 * MySQL 8.4, MariaDB 10.11 and PostgreSQL 17 in the CI database job).
 */
abstract class ShortLinkFunctionalTestCase extends KernelTestCase
{
    protected TestKernel $kernel;

    protected TenantKey $tenant;

    protected TenantKey $otherTenant;

    abstract protected function host(): Host;

    /**
     * Grants view + manage in the given tenants (standard host: a ROLE_ADMIN token, every tenant).
     *
     * @param list<TenantKey> $tenants
     */
    abstract protected function grant(array $tenants, ?string $actor = 'actor-1'): void;

    /**
     * Removes every permission.
     */
    abstract protected function revoke(): void;

    /**
     * @param array<string, mixed> $config
     */
    protected function boot(array $config = []): void
    {
        $host         = $this->host();
        $this->kernel = $this->bootKernel($host->bundleConfig($config), TestKernel::defaultDatabaseUrl(), $host->configure(), $host->kind === Host::STANDARD);
        $host->resetSchema($this->kernel);
        $this->listener()->clear();
        $this->grant([$this->tenant, $this->otherTenant]);
    }

    protected function setUp(): void
    {
        $this->boot();
    }

    public function testCreateFindAndListALinkWithEventsAfterCommit(): void
    {
        $view = $this->manager()->create($this->tenant, new CreateShortLink('https://Example.COM:443/path?q=1#top', title: " Launch\u{202E} page ", metadata: LinkMetadata::fromArray(['campaign' => 'q4'])));

        self::assertSame('https://example.com/path?q=1#top', $view->target->toString());
        self::assertSame('Launch page', $view->title?->value);
        self::assertSame(ShortLinkState::Active, $view->state);
        self::assertFalse($view->code->isCustom());
        self::assertSame(7, strlen($view->code->value));
        self::assertSame('https://links.example/s/' . $view->code->value, $view->shortUrl);
        self::assertSame('actor-1', $view->createdBy?->value);
        self::assertSame(LinkKind::MANUAL, $view->kind->value);

        $created = $this->listener()->of(ShortLinkCreatedEvent::class);
        self::assertCount(1, $created);
        self::assertSame('example.com', $created[0]->targetHost);
        self::assertFalse($created[0]->system);

        $found = $this->manager()->find($this->tenant, $view->id);
        self::assertInstanceOf(ShortLinkView::class, $found);
        self::assertSame(['campaign' => 'q4'], $found->metadata?->values);
        self::assertSame($view->code->value, $found->code->value);
        self::assertNull($this->manager()->find($this->otherTenant, $view->id));

        $page = $this->manager()->list($this->tenant, new ShortLinkListQuery());
        self::assertCount(1, $page->items);
        self::assertNull($page->next);
        self::assertSame([], $this->manager()->list($this->otherTenant, new ShortLinkListQuery())->items);
    }

    public function testCustomCodesAreLowercasedUniquePerInstanceAndNeutral(): void
    {
        $view = $this->manager()->create($this->tenant, new CreateShortLink('https://example.com', customCode: 'Launch-2026'));
        self::assertSame('launch-2026', $view->code->value);
        self::assertTrue($view->code->isCustom());

        foreach ([$this->tenant, $this->otherTenant] as $tenant) {
            try {
                $this->manager()->create($tenant, new CreateShortLink('https://example.com', customCode: 'LAUNCH-2026'));
                self::fail('A taken code must be refused.');
            } catch (CodeTakenException $exception) {
                self::assertSame(CodeTakenException::MESSAGE, $exception->getMessage());
            }
        }

        $this->expectException(CodeReservedException::class);
        $this->expectExceptionMessage(CodeTakenException::MESSAGE);
        $this->manager()->create($this->tenant, new CreateShortLink('https://example.com', customCode: 'Admin'));
    }

    public function testRandomCodesAreCaseSensitiveAndExact(): void
    {
        $store = $this->store();
        $now   = new DateTimeImmutable(Host::NOW);
        foreach (['aBcdEf1' => '0190a6d0-0000-7000-8000-000000000001', 'ABCDEF1' => '0190a6d0-0000-7000-8000-000000000002'] as $code => $id) {
            $store->insert(new StoredLink(ShortLinkId::fromString($id), $this->tenant, ShortCode::random($code), TargetUrl::fromString('https://example.com/' . $code), null, LinkKind::manual(), null, null, true, null, null, $now, $now, null, null, null));
        }

        self::assertTrue($store->codeExists('aBcdEf1'));
        self::assertTrue($store->codeExists('ABCDEF1'));
        self::assertFalse($store->codeExists('abcdef1'));
        self::assertSame('https://example.com/ABCDEF1', $store->find($this->tenant, ShortLinkId::fromString('0190a6d0-0000-7000-8000-000000000002'))?->target->toString());

        $this->expectException(CodeTakenException::class);
        $store->insert(new StoredLink(ShortLinkId::fromString('0190a6d0-0000-7000-8000-000000000003'), $this->otherTenant, ShortCode::random('aBcdEf1'), TargetUrl::fromString('https://example.com'), null, LinkKind::manual(), null, null, true, null, null, $now, $now, null, null, null));
    }

    public function testRefusedTargetsNameTheReasonAndRaiseAnEvent(): void
    {
        $cases = [
            'javascript:alert(1)'            => TargetRefusal::Scheme,
            'https://user:pass@example.com/' => TargetRefusal::Userinfo,
            'http://127.0.0.1/admin'         => TargetRefusal::PrivateHost,
            'http://[::1]/'                  => TargetRefusal::PrivateHost,
            'http://169.254.169.254/latest'  => TargetRefusal::Metadata,
            'https://cdn.bad.example/x'      => TargetRefusal::Denylisted,
            'https://links.example/s/abc'    => TargetRefusal::SelfLoop,
        ];
        foreach ($cases as $url => $refusal) {
            try {
                $this->manager()->create($this->tenant, new CreateShortLink($url));
                self::fail($url . ' must be refused');
            } catch (TargetRefusedException $exception) {
                self::assertSame($refusal, $exception->refusal, $url);
            }
        }

        $events = $this->listener()->of(ShortLinkTargetRefusedEvent::class);
        self::assertCount(5, $events, 'guard refusals raise an event; VO refusals (scheme, userinfo) do not');
        self::assertSame('127.0.0.1', $events[0]->targetHost);
        self::assertNull($events[0]->id);
        self::assertSame([], $this->listener()->of(ShortLinkCreatedEvent::class));
    }

    public function testUpdateChangesTheEditableFieldsOnly(): void
    {
        $view = $this->manager()->create($this->tenant, new CreateShortLink('https://example.com/a', title: 'A'));
        $this->listener()->clear();

        $same = $this->manager()->update($this->tenant, $view->id, new UpdateShortLink('https://example.com/a', 'A'));
        self::assertSame($view->updatedAt->format('c'), $same->updatedAt->format('c'));
        self::assertSame([], $this->listener()->events);

        $this->clock()->modify('+1 minute');
        $this->grant([$this->tenant, $this->otherTenant], 'actor-2');
        $updated = $this->manager()->update($this->tenant, $view->id, new UpdateShortLink('https://other.example/b', null, new DateTimeImmutable('2027-01-01 00:00:00', new DateTimeZone('Europe/Madrid'))));
        self::assertSame('https://other.example/b', $updated->target->toString());
        self::assertNull($updated->title);
        self::assertSame('2026-12-31 23:00:00', $updated->expiry?->at->format('Y-m-d H:i:s'));
        self::assertSame('actor-2', $updated->updatedBy?->value);
        self::assertSame('actor-1', $updated->createdBy?->value);
        self::assertSame($view->code->value, $updated->code->value);

        $events = $this->listener()->of(ShortLinkUpdatedEvent::class);
        self::assertCount(1, $events);
        self::assertSame(['target', 'title', 'expires_at'], $events[0]->changedFields);
        self::assertSame('example.com', $events[0]->oldTargetHost);
        self::assertSame('other.example', $events[0]->newTargetHost);

        $reloaded = $this->manager()->find($this->tenant, $view->id);
        self::assertSame('https://other.example/b', $reloaded?->target->toString());
        self::assertSame('2026-12-31 23:00:00', $reloaded->expiry?->at->format('Y-m-d H:i:s'));

        $this->expectException(ShortLinkNotFoundException::class);
        $this->manager()->update($this->otherTenant, $view->id, new UpdateShortLink('https://example.com'));
    }

    public function testExpiryMustBeInTheFutureAndExpiredLinksAreListedApart(): void
    {
        try {
            $this->manager()->create($this->tenant, new CreateShortLink('https://example.com', expiresAt: new DateTimeImmutable(Host::NOW)));
            self::fail('An expiry that is not in the future is refused.');
        } catch (InvalidShortLinkArgumentException) {
        }
        $soon = $this->manager()->create($this->tenant, new CreateShortLink('https://example.com/soon', title: 'Soon', expiresAt: new DateTimeImmutable('2026-11-12 11:00:00')));
        $off  = $this->manager()->create($this->tenant, new CreateShortLink('https://example.com/off', title: 'Off', active: false));
        $live = $this->manager()->create($this->tenant, new CreateShortLink('https://example.com/live', title: 'Live 100%_'));
        self::assertSame(ShortLinkState::Disabled, $off->state);
        self::assertSame('manual', $off->disabledReason);

        $this->clock()->modify('+2 hours');
        self::assertSame(ShortLinkState::Expired, $this->manager()->find($this->tenant, $soon->id)?->state);
        self::assertSame([$live->id->value], $this->ids(new ShortLinkListQuery(state: ShortLinkState::Active)));
        self::assertSame([$off->id->value], $this->ids(new ShortLinkListQuery(state: ShortLinkState::Disabled)));
        self::assertSame([$soon->id->value], $this->ids(new ShortLinkListQuery(state: ShortLinkState::Expired)));
        self::assertSame([], $this->ids(new ShortLinkListQuery(state: ShortLinkState::Deleted)));
        self::assertSame([$live->id->value], $this->ids(new ShortLinkListQuery(search: '100%_')));
        self::assertSame([$live->id->value, $off->id->value, $soon->id->value], $this->ids(new ShortLinkListQuery(search: 'example.com')));
        self::assertSame([$soon->id->value], $this->ids(new ShortLinkListQuery(search: $soon->code->value)));
    }

    public function testKeysetPagesAreNewestFirst(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; ++$i) {
            $ids[] = $this->manager()->create($this->tenant, new CreateShortLink('https://example.com/' . $i, kind: LinkKind::email()))->id->value;
        }
        $this->manager()->create($this->tenant, new CreateShortLink('https://example.com/manual'));

        $first = $this->manager()->list($this->tenant, new ShortLinkListQuery(kind: LinkKind::email(), limit: 2));
        self::assertSame([$ids[4], $ids[3]], array_map(static fn (ShortLinkView $v): string => $v->id->value, $first->items));
        self::assertSame($ids[3], $first->next?->value);
        $second = $this->manager()->list($this->tenant, new ShortLinkListQuery(kind: LinkKind::email(), limit: 2, after: $first->next));
        self::assertSame([$ids[2], $ids[1]], array_map(static fn (ShortLinkView $v): string => $v->id->value, $second->items));
        $last = $this->manager()->list($this->tenant, new ShortLinkListQuery(kind: LinkKind::email(), limit: 2, after: $second->next));
        self::assertSame([$ids[0]], array_map(static fn (ShortLinkView $v): string => $v->id->value, $last->items));
        self::assertNull($last->next);

        self::assertCount(1, $this->manager()->list($this->tenant, new ShortLinkListQuery())->items, 'e-mail links are hidden from the default list');
    }

    public function testDisableEnableAndDeleteAreTenantScopedAndTombstonesKeepTheCode(): void
    {
        $view = $this->manager()->create($this->tenant, new CreateShortLink('https://example.com', customCode: 'flyer'));
        $this->listener()->clear();

        $this->manager()->disable($this->tenant, $view->id);
        $this->manager()->disable($this->tenant, $view->id);
        self::assertSame(ShortLinkState::Disabled, $this->manager()->find($this->tenant, $view->id)?->state);
        $this->manager()->enable($this->tenant, $view->id);
        $this->manager()->enable($this->tenant, $view->id);
        self::assertSame(ShortLinkState::Active, $this->manager()->find($this->tenant, $view->id)?->state);
        $changes = $this->listener()->of(ShortLinkStateChangedEvent::class);
        self::assertCount(2, $changes);
        self::assertSame([ShortLinkState::Active, ShortLinkState::Disabled], [$changes[0]->from, $changes[0]->to]);
        self::assertSame([ShortLinkState::Disabled, ShortLinkState::Active], [$changes[1]->from, $changes[1]->to]);
        self::assertSame(ShortLinkStateChangedEvent::REASON_MANUAL, $changes[1]->reason);

        foreach (['disable', 'enable', 'delete'] as $action) {
            try {
                $this->manager()->{$action}($this->otherTenant, $view->id);
                self::fail($action . ' of another tenant\'s link must answer not found');
            } catch (ShortLinkNotFoundException) {
            }
        }

        $this->manager()->delete($this->tenant, $view->id);
        self::assertNull($this->manager()->find($this->tenant, $view->id));
        self::assertSame('flyer', $this->listener()->of(ShortLinkDeletedEvent::class)[0]->code->value);
        self::assertTrue($this->store()->codeExists('flyer'));

        $this->expectException(CodeTakenException::class);
        $this->manager()->create($this->otherTenant, new CreateShortLink('https://evil.example', customCode: 'flyer'));
    }

    public function testEnableRunsTheSaveGuardAgain(): void
    {
        $view  = $this->manager()->create($this->tenant, new CreateShortLink('https://example.com', active: false));
        $store = $this->store();
        $row   = $store->find($this->tenant, $view->id);
        self::assertInstanceOf(StoredLink::class, $row);
        $store->update($row->withEdit(TargetUrl::fromString('https://x.bad.example'), null, null, new DateTimeImmutable(Host::NOW), null));

        try {
            $this->manager()->enable($this->tenant, $view->id);
            self::fail('A denylisted target cannot be enabled.');
        } catch (TargetRefusedException $exception) {
            self::assertSame(TargetRefusal::Denylisted, $exception->refusal);
        }
        self::assertSame($view->id->value, $this->listener()->of(ShortLinkTargetRefusedEvent::class)[0]->id?->value);
        self::assertSame(ShortLinkState::Disabled, $this->manager()->find($this->tenant, $view->id)?->state);
    }

    public function testQuotaCountsLiveLinksOfCountedKindsOnly(): void
    {
        $this->setLimit(2);
        $first = $this->manager()->create($this->tenant, new CreateShortLink('https://example.com/1'));
        $this->manager()->create($this->tenant, new CreateShortLink('https://example.com/2'));
        $this->manager()->create($this->tenant, new CreateShortLink('https://example.com/e', kind: LinkKind::email()));

        try {
            $this->manager()->create($this->tenant, new CreateShortLink('https://example.com/3'));
            self::fail('The third manual link exceeds the limit.');
        } catch (QuotaExceededException $exception) {
            self::assertSame(2, $exception->limit);
        }
        try {
            $this->manager()->createMany($this->tenant, [new CreateShortLink('https://example.com/4')]);
            self::fail('createMany() respects the limit.');
        } catch (QuotaExceededException) {
        }

        $this->manager()->delete($this->tenant, $first->id);
        $this->manager()->create($this->tenant, new CreateShortLink('https://example.com/3'));
        self::assertCount(2, $this->manager()->list($this->tenant, new ShortLinkListQuery(kind: LinkKind::manual()))->items);
    }

    public function testCreateManyDeduplicatesBySourceAndPosition(): void
    {
        $this->revoke();
        $commands = [
            new CreateShortLink('https://example.com/a', kind: LinkKind::email(), source: new LinkSource('send-1', 'Header logo', 0), system: true),
            new CreateShortLink('https://example.com/b', kind: LinkKind::email(), source: new LinkSource('send-1', 'Button', 1), system: true),
            new CreateShortLink('https://example.com/b', kind: LinkKind::email(), source: new LinkSource('send-1', 'Button again', 1), system: true),
            new CreateShortLink('https://example.com/c', kind: LinkKind::email(), system: true),
        ];
        $views = $this->manager()->createMany($this->tenant, $commands);

        self::assertCount(4, $views);
        self::assertSame($views[1]->id->value, $views[2]->id->value);
        self::assertSame('Button', $views[2]->source?->label);
        self::assertSame(1, $views[1]->source?->position);
        self::assertCount(3, $this->listener()->of(ShortLinkCreatedEvent::class));
        self::assertTrue($this->listener()->of(ShortLinkCreatedEvent::class)[0]->system);

        $this->listener()->clear();
        $retry = $this->manager()->createMany($this->tenant, [$commands[0], $commands[1]]);
        self::assertSame([$views[0]->id->value, $views[1]->id->value], [$retry[0]->id->value, $retry[1]->id->value]);
        self::assertSame([], $this->listener()->events);
        self::assertSame([], $this->manager()->createMany($this->tenant, []));

        $other = $this->manager()->createMany($this->otherTenant, [$commands[0]]);
        self::assertNotSame($views[0]->id->value, $other[0]->id->value, 'the same source in another tenant is another link');
    }

    public function testCreateManyRefusesCustomCodesAndLargeBatches(): void
    {
        try {
            $this->manager()->createMany($this->tenant, [new CreateShortLink('https://example.com', customCode: 'mine')]);
            self::fail('createMany() creates random codes only.');
        } catch (InvalidShortLinkArgumentException) {
        }

        $this->expectException(InvalidShortLinkArgumentException::class);
        $this->manager()->createMany($this->tenant, array_map(static fn (int $i): CreateShortLink => new CreateShortLink('https://example.com/' . $i), range(0, 200)));
    }

    public function testAccessIsCheckedUnlessTheCommandIsSystem(): void
    {
        $this->revoke();
        foreach ([
            fn (): mixed => $this->manager()->create($this->tenant, new CreateShortLink('https://example.com')),
            fn (): mixed => $this->manager()->find($this->tenant, ShortLinkId::fromString('0190a6d0-0000-7000-8000-000000000009')),
            fn (): mixed => $this->manager()->list($this->tenant, new ShortLinkListQuery()),
            fn (): mixed => $this->manager()->createMany($this->tenant, [new CreateShortLink('https://example.com')]),
        ] as $call) {
            try {
                $call();
                self::fail('Access must be denied.');
            } catch (AccessDeniedException) {
            }
        }

        $view = $this->manager()->create($this->tenant, new CreateShortLink('https://example.com', system: true));
        self::assertNull($view->createdBy);
        self::assertTrue($this->listener()->of(ShortLinkCreatedEvent::class)[0]->system);
    }

    public function testEventsWaitForTheHostCommitAndAreDroppedOnRollback(): void
    {
        $connection = $this->host()->connection($this->kernel);

        $connection->beginTransaction();
        $this->manager()->create($this->tenant, new CreateShortLink('https://example.com/rolled-back'));
        self::assertSame([], $this->listener()->events, 'nothing is dispatched inside the host transaction');
        $connection->rollBack();
        self::assertSame([], $this->listener()->events);
        self::assertSame([], $this->manager()->list($this->tenant, new ShortLinkListQuery())->items);

        $connection->beginTransaction();
        $this->manager()->create($this->tenant, new CreateShortLink('https://example.com/committed'));
        self::assertSame([], $this->listener()->events);
        $connection->commit();
        self::assertCount(1, $this->listener()->of(ShortLinkCreatedEvent::class));
    }

    public function testUnknownKindsAndForbiddenCustomCodesAreRefused(): void
    {
        foreach ([
            new CreateShortLink('https://example.com', kind: LinkKind::fromString('qr')),
            new CreateShortLink('https://example.com', customCode: 'mail-me', kind: LinkKind::email()),
        ] as $command) {
            try {
                $this->manager()->create($this->tenant, $command);
                self::fail('Refused');
            } catch (InvalidShortLinkArgumentException) {
            }
        }
        self::assertCount(0, $this->listener()->events);
    }

    public function testStoredRowsKeepEveryColumn(): void
    {
        $now  = new DateTimeImmutable(Host::NOW);
        $link = new StoredLink(
            ShortLinkId::fromString('0190a6d0-0000-7000-8000-0000000000aa'),
            $this->tenant,
            ShortCode::custom('all-columns'),
            TargetUrl::fromString('https://example.com/x'),
            LinkTitle::fromString('Title'),
            LinkKind::email(),
            new LinkSource('send-9', 'Label'),
            LinkMetadata::fromArray(['a' => 1, 'b' => null, 'c' => 'x']),
            false,
            Expiry::fromDateTime($now->modify('+1 day')),
            'recheck_refused',
            $now,
            $now,
            null,
            ActorRef::fromString('UserA'),
            ActorRef::fromString('UserB'),
        );
        $this->store()->insert($link);

        $read = $this->store()->findBySource($this->tenant, LinkKind::email(), 'send-9', null);
        self::assertEquals($link, $read);
        self::assertNull($this->store()->findBySource($this->tenant, LinkKind::email(), 'send-9', 3));
        self::assertNull($this->store()->findBySource($this->otherTenant, LinkKind::email(), 'send-9', null));
        self::assertSame(1, $this->store()->countLive($this->tenant, LinkKind::email()));
        self::assertSame(42, $this->store()->transactional(static fn (): int => 42));
        self::assertSame([], $this->store()->list($this->tenant, new ShortLinkListQuery(), [], $now));
    }

    public function testOneKeptAliveKernelServesManyCallsWithoutBleedOrGrowth(): void
    {
        $this->revoke();
        $baseline = 0;
        for ($i = 0; $i < 300; ++$i) {
            [$mine, $theirs] = $i % 2 === 0 ? [$this->tenant, $this->otherTenant] : [$this->otherTenant, $this->tenant];
            $view            = $this->manager()->create($mine, new CreateShortLink('https://example.com/' . $i, kind: LinkKind::email(), system: true));
            self::assertSame($mine->value, $view->tenant->value);
            self::assertNull($this->store()->find($theirs, $view->id), 'request ' . $i . ' never sees the other tenant');
            if ($i === 50) {
                $this->listener()->clear();
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        $this->listener()->clear();
        gc_collect_cycles();

        self::assertLessThan(1024 * 1024, memory_get_usage() - $baseline, 'memory stays flat');
        self::assertSame(150, $this->store()->countLive($this->tenant, LinkKind::email()));
    }

    public function testTheSchemaMatchesTheMapping(): void
    {
        $connection = $this->host()->connection($this->kernel);
        $actual     = $connection->createSchemaManager()->introspectSchema();
        $prefix     = $this->host()->tablePrefix();

        foreach (['nowo_short_link', 'nowo_short_link_click', 'nowo_short_link_click_daily', 'nowo_short_link_rollup_day'] as $table) {
            self::assertTrue($actual->hasTable($prefix . $table), $table);
        }
        $pending = [];
        foreach ($this->host()->updateSchemaSql($this->kernel) as $sql) {
            if (preg_match('/(?<![A-Za-z0-9_])' . $prefix . 'nowo_short_link/', $sql) === 1) {
                $pending[] = $sql;
            }
        }
        self::assertSame([], $pending, 'doctrine:schema:update has nothing left to do for the bundle tables');
        self::assertTrue($actual->getTable($prefix . 'nowo_short_link')->hasIndex($prefix . 'uniq_short_link_code'));

        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::assertSame('utf8mb4_bin', $actual->getTable($prefix . 'nowo_short_link')->getColumn('code')->getPlatformOption('collation'));
        }
    }

    protected function manager(): ShortLinkManagerInterface
    {
        $manager = $this->container()->get(ShortLinkManagerInterface::class);
        self::assertInstanceOf(ShortLinkManagerInterface::class, $manager);

        return $manager;
    }

    protected function store(): ShortLinkStoreInterface
    {
        $store = $this->container()->get(ShortLinkStoreInterface::class);
        self::assertInstanceOf(ShortLinkStoreInterface::class, $store);

        return $store;
    }

    protected function listener(): RecordingListener
    {
        $listener = $this->container()->get(RecordingListener::class);
        self::assertInstanceOf(RecordingListener::class, $listener);

        return $listener;
    }

    protected function clock(): MockClock
    {
        $clock = $this->container()->get('clock');
        self::assertInstanceOf(MockClock::class, $clock);

        return $clock;
    }

    abstract protected function setLimit(int $limit): void;

    protected function container(): ContainerInterface
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    /**
     * @return list<string>
     */
    private function ids(ShortLinkListQuery $query): array
    {
        return array_map(static fn (ShortLinkView $view): string => $view->id->value, $this->manager()->list($this->tenant, $query)->items);
    }
}
