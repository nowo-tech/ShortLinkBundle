<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit\Application;

use Closure;
use DateTimeImmutable;
use Nowo\ShortLinkBundle\Application\CodeGenerator;
use Nowo\ShortLinkBundle\Application\Default\ConfigDenylist;
use Nowo\ShortLinkBundle\Application\Default\ConfigQuota;
use Nowo\ShortLinkBundle\Application\Default\ConfigReservedCodes;
use Nowo\ShortLinkBundle\Application\Guard\DenylistGuard;
use Nowo\ShortLinkBundle\Application\ManagerSettings;
use Nowo\ShortLinkBundle\Application\ShortLinkManager;
use Nowo\ShortLinkBundle\Application\ShortUrlBuilderInterface;
use Nowo\ShortLinkBundle\Application\Store\ShortLinkStoreInterface;
use Nowo\ShortLinkBundle\Application\Store\StoredLink;
use Nowo\ShortLinkBundle\Domain\Model\ActorRef;
use Nowo\ShortLinkBundle\Domain\Model\CreateShortLink;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\ResolvedLink;
use Nowo\ShortLinkBundle\Domain\Model\ShortCode;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkListQuery;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkPermission;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkAccessInterface;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkIdGeneratorInterface;
use Nowo\ShortLinkBundle\Infrastructure\Lock\NoTenantLock;
use Nowo\ShortLinkBundle\Tests\Fixtures\MemoryLogger;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

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

    private function manager(ScriptedStore $store, ManagerSettings $settings, ?MemoryLogger $logger = null): ShortLinkManager
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
        $events = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };

        return new ShortLinkManager($store, new CodeGenerator(new ConfigReservedCodes(), null), new ConfigReservedCodes(), new DenylistGuard(new ConfigDenylist()), $deny, new ConfigQuota(null), new NoTenantLock(), $ids, $urls, $events, $settings, null, $logger);
    }
}

/**
 * Store whose first N code probes collide.
 */
class ScriptedStore implements ShortLinkStoreInterface
{
    public int $probes = 0;

    public function __construct(private readonly int $collisions = 0)
    {
    }

    public function transactional(Closure $work, ?Closure $afterCommit = null): mixed
    {
        $result = $work();
        if ($afterCommit instanceof Closure) {
            $afterCommit($result);
        }

        return $result;
    }

    public function codeExists(string $code): bool
    {
        return ++$this->probes <= $this->collisions;
    }

    public function insert(StoredLink $link): void
    {
    }

    public function update(StoredLink $link): void
    {
    }

    public function findActiveByCode(string $code, DateTimeImmutable $now): ?ResolvedLink
    {
        return null;
    }

    public function activeAfter(?ShortLinkId $after, int $limit): array
    {
        return [];
    }

    public function find(TenantKey $tenant, ShortLinkId $id): ?StoredLink
    {
        return null;
    }

    public function findBySource(TenantKey $tenant, LinkKind $kind, string $ref, ?int $position): ?StoredLink
    {
        return null;
    }

    public function countLive(TenantKey $tenant, LinkKind $kind): int
    {
        return 0;
    }

    public function list(TenantKey $tenant, ShortLinkListQuery $query, array $kinds, DateTimeImmutable $now): array
    {
        return [];
    }
}
