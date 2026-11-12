<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\OutboundUrlGuardBundle\Dns\HostnameDnsLookupInterface;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Nowo\ShortLinkBundle\Application\Store\StoredLink;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkTargetRefusedEvent;
use Nowo\ShortLinkBundle\Domain\Exception\InvalidShortLinkArgumentException;
use Nowo\ShortLinkBundle\Domain\Model\DateRange;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\ShortCode;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\TargetRefusal;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Infrastructure\Console\RecheckCommand;
use Nowo\ShortLinkBundle\Infrastructure\Guard\OutboundUrlGuardBridge;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\ClickTables;
use Nowo\ShortLinkBundle\Tests\Unit\Application\ScriptedStore;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use stdClass;
use Symfony\Component\Console\Tester\CommandTester;

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
            /** @var list<StoredLink> */
            public array $updated = [];

            public function __construct(private readonly StoredLink $link)
            {
                parent::__construct();
            }

            public function activeAfter(?ShortLinkId $after, int $limit): array
            {
                return $after === null ? [$this->link] : [];
            }

            public function update(StoredLink $link): void
            {
                $this->updated[] = $link;
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
        self::assertFalse($store->updated[0]->active);
        self::assertSame('recheck_refused', $store->updated[0]->disabledReason);
        self::assertInstanceOf(ShortLinkTargetRefusedEvent::class, $events->events[0]);
        self::assertSame(TargetRefusal::PrivateHost, $events->events[0]->refusal);
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
