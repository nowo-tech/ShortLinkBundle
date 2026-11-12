<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\OutboundUrlGuardBundle\Dns\HostnameDnsLookupInterface;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Nowo\ShortLinkBundle\Application\Click\ClickPayload;
use Nowo\ShortLinkBundle\Application\Click\ClickRecorder;
use Nowo\ShortLinkBundle\Application\Default\NullCountryResolver;
use Nowo\ShortLinkBundle\Application\Store\ActiveBatch;
use Nowo\ShortLinkBundle\Application\Store\ClickStoreInterface;
use Nowo\ShortLinkBundle\Application\Store\StoredLink;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkTargetRefusedEvent;
use Nowo\ShortLinkBundle\Domain\Exception\InvalidShortLinkArgumentException;
use Nowo\ShortLinkBundle\Domain\Model\DateRange;
use Nowo\ShortLinkBundle\Domain\Model\GuardStage;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\ShortCode;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\TargetRefusal;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;
use Nowo\ShortLinkBundle\Domain\Model\TargetVerdict;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Model\UserAgentFamily;
use Nowo\ShortLinkBundle\Domain\Port\TargetGuardInterface;
use Nowo\ShortLinkBundle\Infrastructure\Console\RecheckCommand;
use Nowo\ShortLinkBundle\Infrastructure\Console\RollupCommand;
use Nowo\ShortLinkBundle\Infrastructure\Doctrine\ClickTablesSchemaListener;
use Nowo\ShortLinkBundle\Infrastructure\Guard\OutboundUrlGuardBridge;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\ClickTables;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\DbalClickMaintenance;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\DbalEraser;
use Nowo\ShortLinkBundle\Tests\Fixtures\MemoryLogger;
use Nowo\ShortLinkBundle\Tests\Fixtures\ScriptedStore;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

use function array_values;
use function sprintf;

use const DNS_A;

/**
 * Date ranges and the connection choice of the click tables.
 */
final class StatsInfrastructureTest extends TestCase
{
    public function testDateRanges(): void
    {
        $range = DateRange::of(new DateTimeImmutable('2026-11-12 23:30:00-02:00'), new DateTimeImmutable('2026-11-14 00:30:00+02:00'));
        self::assertSame(['2026-11-13'], $range->days(), 'only the UTC day counts');
        self::assertSame(['2026-11-10', '2026-11-11', '2026-11-12'], DateRange::lastDays(3, new DateTimeImmutable('2026-11-12 10:00:00'))->days());
        self::assertCount(366, DateRange::lastDays(366, new DateTimeImmutable('2026-11-12'))->days());

        foreach ([['2026-11-12', '2026-11-11'], ['2025-01-01', '2026-01-02']] as [$from, $to]) {
            try {
                DateRange::of(new DateTimeImmutable($from), new DateTimeImmutable($to));
                self::fail('Refused');
            } catch (InvalidShortLinkArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRecheckDisablesALinkWhoseHostNowResolvesPrivate(): void
    {
        $now   = new DateTimeImmutable('2026-11-12 10:00:00');
        $link  = new StoredLink(ShortLinkId::fromString('0190a6d0-0000-7000-8000-000000000001'), TenantKey::default(), ShortCode::random('aB3xY9k'), TargetUrl::fromString('https://moved.example/'), null, LinkKind::manual(), null, null, true, null, null, $now, $now, null, null, null);
        $store = new class($link) extends ScriptedStore {
            /** @var list<array{StoredLink, string}> */
            public array $disabled = [];

            public function __construct(private readonly StoredLink $link)
            {
                parent::__construct();
            }

            public function activeAfter(?string $afterId, int $limit): ActiveBatch
            {
                return $afterId === null ? new ActiveBatch([$this->link], $this->link->id->value, 1) : new ActiveBatch([], null, 0);
            }

            public function disableIfUnchanged(StoredLink $checked, string $reason, DateTimeImmutable $now): bool
            {
                $this->disabled[] = [$checked, $reason];

                return true;
            }
        };
        $dns = new class implements HostnameDnsLookupInterface {
            public function dnsGetRecord(string $hostname, int $type): array
            {
                return $type === DNS_A ? [['ip' => '10.0.0.5']] : [];
            }

            public function hostByNameL(string $hostname): false
            {
                return false;
            }
        };
        $events = new class implements EventDispatcherInterface {
            /** @var list<object> */
            public array $events = [];

            public function dispatch(object $event): object
            {
                $this->events[] = $event;

                return $event;
            }
        };
        $command = new RecheckCommand($store, new OutboundUrlGuardBridge(new OutboundUrlGuard(false, true, $dns), true), $events);
        $tester  = new CommandTester($command);
        $tester->execute([]);

        self::assertStringContainsString('disabled 1', $tester->getDisplay());
        self::assertSame('recheck_refused', $store->disabled[0][1]);
        self::assertInstanceOf(ShortLinkTargetRefusedEvent::class, $events->events[0]);
        self::assertSame(TargetRefusal::PrivateHost, $events->events[0]->refusal);
    }

    public function testRecheckSkipsUnresolvableTargetsAbortsOnAnOutageAndHasADryRun(): void
    {
        $now   = new DateTimeImmutable('2026-11-12 10:00:00');
        $links = [];
        for ($i = 1; $i <= 10; ++$i) {
            $links[] = new StoredLink(ShortLinkId::fromString(sprintf('0190a6d0-0000-7000-8000-%012d', $i)), TenantKey::default(), ShortCode::random('aB3xY9' . $i . 'x'), TargetUrl::fromString('https://h' . $i . '.example/'), null, LinkKind::manual(), null, null, true, null, null, $now, $now, null, null, null);
        }
        $store = new class($links) extends ScriptedStore {
            public int $disables = 0;

            public bool $changes = true;

            /** @param list<StoredLink> $links */
            public function __construct(private readonly array $links)
            {
                parent::__construct();
            }

            public function activeAfter(?string $afterId, int $limit): ActiveBatch
            {
                return $afterId === null ? new ActiveBatch($this->links, '0190a6d0-0000-7000-8000-000000000010', 10) : new ActiveBatch([], null, 0);
            }

            public function disableIfUnchanged(StoredLink $checked, string $reason, DateTimeImmutable $now): bool
            {
                ++$this->disables;

                return $this->changes;
            }
        };
        $guard = new class implements TargetGuardInterface {
            /** @var array<string, TargetRefusal> */
            public array $answers = [];

            public function check(TargetUrl $target, GuardStage $stage): TargetVerdict
            {
                $refusal = $this->answers[$target->host()] ?? null;

                return $refusal === null ? TargetVerdict::allow() : TargetVerdict::refuse($refusal);
            }
        };
        $events = new class implements EventDispatcherInterface {
            public int $count = 0;

            public function dispatch(object $event): object
            {
                ++$this->count;

                return $event;
            }
        };
        $logger = new MemoryLogger();
        $run    = static function (array $options = [], int $minSample = 20, int $percent = 20) use ($store, $guard, $events, $logger): CommandTester {
            $tester = new CommandTester(new RecheckCommand($store, $guard, $events, null, $percent, $logger, $minSample));
            $tester->execute($options);

            return $tester;
        };

        $guard->answers = ['h1.example' => TargetRefusal::Unresolvable, 'h2.example' => TargetRefusal::Denylisted];
        self::assertStringContainsString('Checked 10 link(s), disabled 1, skipped 1 unresolvable.', $run()->getDisplay());
        self::assertSame(2, $events->count);
        self::assertCount(1, $logger->records, 'an unresolvable target is skipped with a warning, never disabled');

        $store->changes = false;
        $events->count  = 0;
        self::assertStringContainsString('disabled 0', $run()->getDisplay(), 'a link changed meanwhile is not disabled');
        self::assertSame(0, $events->count, 'no events when no row changed');

        $store->disables = 0;
        $dry             = $run(['--dry-run' => true]);
        self::assertStringContainsString('Would disable 0190a6d0-0000-7000-8000-000000000002 (denylisted).', $dry->getDisplay());
        self::assertStringContainsString('[dry run] Checked 10 link(s), disabled 1', $dry->getDisplay());
        self::assertSame(0, $store->disables, 'the dry run writes nothing');

        $store->changes  = true;
        $guard->answers  = ['h1.example' => TargetRefusal::Unresolvable, 'h2.example' => TargetRefusal::Unresolvable, 'h3.example' => TargetRefusal::Unresolvable, 'h4.example' => TargetRefusal::Denylisted];
        $store->disables = 0;
        $tester          = $run();
        self::assertSame(0, $tester->getStatusCode(), 'below the minimum sample (20) the threshold does not apply');
        self::assertStringContainsString('skipped 3 unresolvable', $tester->getDisplay());
        self::assertSame(1, $store->disables);

        $store->disables = 0;
        $tester          = $run([], 5);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Aborted: 3 of 10 checked targets could not be resolved (DNS outage?). Disabled 1.', $tester->getDisplay());
        self::assertSame(1, $store->disables, 'the non-DNS refusals of the batch are applied before aborting');

        $guard->answers = ['h1.example' => TargetRefusal::Unresolvable];
        self::assertSame(1, $run([], 0, 0)->getStatusCode(), 'percent 0 with no minimum sample aborts on any unresolvable target');
        self::assertSame(0, $run([], 20, 0)->getStatusCode(), 'percent 0 waits for the minimum sample');
    }

    public function testTheRollupIsSerialisedWithSymfonyLock(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $locks    = new LockFactory(new InMemoryStore());
        $held     = $locks->createLock('nowo_short_link.rollup');
        $held->acquire();
        $command = new RollupCommand(new DbalClickMaintenance(new ClickTables($registry)), locks: $locks);
        $tester  = new CommandTester($command);
        $tester->execute([]);
        self::assertStringContainsString('Another rollup is running.', $tester->getDisplay());
    }

    public function testARollupDayWrittenConcurrentlyIsSkipped(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema     = new Schema();
        (new ClickTablesSchemaListener())->addTables($schema, false);
        foreach ($schema->toSql($connection->getDatabasePlatform()) as $sql) {
            $connection->executeStatement($sql);
        }
        $connection->insert('nowo_short_link_click', ['link_id' => '0190a6d0-0000-7000-8000-000000000001', 'tenant_key' => 'default', 'clicked_at' => '2026-11-11 10:00:00', 'machine' => 0]);
        $connection->executeStatement("CREATE TRIGGER other_run BEFORE INSERT ON nowo_short_link_rollup_day BEGIN SELECT RAISE(ABORT, 'UNIQUE constraint failed: nowo_short_link_rollup_day.day'); END");
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getConnection')->willReturn($connection);
        $logger = new MemoryLogger();

        self::assertSame([], (new DbalClickMaintenance(new ClickTables($registry, 'clicks'), $logger))->rollup(new DateTimeImmutable('2026-11-12 10:00:00'), 2, 50));
        self::assertCount(1, $logger->records);
    }

    public function testAFailingLivenessCheckDropsTheClick(): void
    {
        $store = new class extends ScriptedStore {
            public function isLive(ShortLinkId $id, TenantKey $tenant): bool
            {
                throw new RuntimeException('database down');
            }
        };
        $clicks = $this->createMock(ClickStoreInterface::class);
        $clicks->expects(self::never())->method('insert');
        $logger   = new MemoryLogger();
        $recorder = new ClickRecorder($clicks, new NullCountryResolver(), $this->createStub(EventDispatcherInterface::class), logger: $logger, links: $store);
        $recorder->record(new ClickPayload(ShortLinkId::fromString('0190a6d0-0000-7000-8000-000000000001'), TenantKey::default(), LinkKind::manual(), null, new DateTimeImmutable(), null, UserAgentFamily::Unknown, null, false));
        self::assertCount(1, $logger->records);
    }

    public function testAFailedSourceErasureOnSeparateConnectionsCanBeRetried(): void
    {
        $links  = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $clicks = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $links->executeStatement('CREATE TABLE nowo_short_link (id VARCHAR(36) PRIMARY KEY, tenant_key VARCHAR(64), code VARCHAR(32), kind VARCHAR(32), target_url VARCHAR(2048), target_host VARCHAR(253), title VARCHAR(255), source_ref VARCHAR(64), source_label VARCHAR(128), source_position INTEGER, metadata TEXT, active BOOLEAN, disabled_reason VARCHAR(32), created_at VARCHAR(19), updated_at VARCHAR(19), deleted_at VARCHAR(19), created_by VARCHAR(128), updated_by VARCHAR(128))');
        $links->insert('nowo_short_link', ['id' => '0190a6d0-0000-7000-8000-000000000001', 'tenant_key' => 'default', 'code' => 'aB3xY9k', 'kind' => 'email', 'target_url' => 'https://example.com/', 'target_host' => 'example.com', 'source_ref' => 'send-1', 'source_label' => 'Logo', 'source_position' => 0, 'active' => 1, 'created_at' => '2026-11-12 10:00:00', 'updated_at' => '2026-11-12 10:00:00', 'created_by' => 'actor-1']);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($links);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getConnection')->willReturn($clicks);
        $registry->method('getManager')->willReturn($em);
        $tables = new ClickTables($registry, 'clicks');
        self::assertFalse($tables->sharesConnection());
        $eraser = new DbalEraser($tables);

        try {
            $eraser->eraseSource(TenantKey::default(), LinkKind::email(), 'send-1');
            self::fail('The click tables do not exist yet.');
        } catch (Exception) {
        }
        self::assertSame('send-1', $links->fetchOne('SELECT source_ref FROM nowo_short_link'), 'the source ref survives a failed run');
        self::assertNotNull($links->fetchOne('SELECT deleted_at FROM nowo_short_link'));

        $schema = new Schema();
        (new ClickTablesSchemaListener())->addTables($schema, false);
        foreach ($schema->toSql($clicks->getDatabasePlatform()) as $sql) {
            $clicks->executeStatement($sql);
        }
        $clicks->insert('nowo_short_link_click', ['link_id' => '0190a6d0-0000-7000-8000-000000000001', 'tenant_key' => 'default', 'clicked_at' => '2026-11-12 10:00:00', 'machine' => 0]);
        self::assertSame(2, $eraser->eraseSource(TenantKey::default(), LinkKind::email(), 'send-1'), 'the retry finds the links: 1 click + 1 blanked link');
        self::assertEquals(0, $clicks->fetchOne('SELECT COUNT(*) FROM nowo_short_link_click'));
        self::assertSame([null, '', null], array_values((array) $links->fetchAssociative('SELECT source_ref, target_url, created_by FROM nowo_short_link')));
    }

    public function testTheSameConnectionNamedExplicitlyIsShared(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $em         = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getConnection')->willReturn($connection);
        $registry->method('getManager')->willReturn($em);

        self::assertTrue((new ClickTables($registry, 'default'))->sharesConnection());
        self::assertTrue((new ClickTables($registry))->sharesConnection());
    }

    public function testClickTablesUseTheConfiguredConnection(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $registry   = $this->createStub(ManagerRegistry::class);
        $registry->method('getConnection')->willReturn($connection);
        $tables = new ClickTables($registry, 'clicks', null, 'p_');
        self::assertSame($connection, $tables->clickConnection());
        self::assertSame('p_nowo_short_link_click_daily', $tables->daily);

        $wrong = $this->createStub(ManagerRegistry::class);
        $wrong->method('getConnection')->willReturn(new stdClass());
        $wrong->method('getManager')->willReturn($this->createStub(ObjectManager::class));
        foreach ([static fn (): mixed => (new ClickTables($wrong, 'clicks'))->clickConnection(), static fn (): mixed => (new ClickTables($wrong))->linkConnection()] as $call) {
            try {
                $call();
                self::fail('Refused');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
