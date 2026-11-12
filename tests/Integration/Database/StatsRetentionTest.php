<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration\Database;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nowo\ShortLinkBundle\Application\Store\ClickStoreInterface;
use Nowo\ShortLinkBundle\Application\Store\ShortLinkStoreInterface;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkStateChangedEvent;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkTargetRefusedEvent;
use Nowo\ShortLinkBundle\Domain\Model\ActorRef;
use Nowo\ShortLinkBundle\Domain\Model\ClickDimension;
use Nowo\ShortLinkBundle\Domain\Model\CreateShortLink;
use Nowo\ShortLinkBundle\Domain\Model\DailyClicks;
use Nowo\ShortLinkBundle\Domain\Model\DateRange;
use Nowo\ShortLinkBundle\Domain\Model\DimensionClicks;
use Nowo\ShortLinkBundle\Domain\Model\GuardStage;
use Nowo\ShortLinkBundle\Domain\Model\LinkClickTotals;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\LinkSource;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkPermission;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkState;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkView;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkEraserInterface;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkManagerInterface;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkStatsReaderInterface;
use Nowo\ShortLinkBundle\Tests\Fixtures\Ddd\TestAccess;
use Nowo\ShortLinkBundle\Tests\Fixtures\RecordingListener;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\Host;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_map;
use function sort;
use function trim;

/**
 * S3 end to end (contract §5.3, §10, §13): rollup, purge, recheck, the stats reader across the rollup / raw boundary,
 * the e-mail log and the GDPR eraser, on the standard host (the DDD subclass runs the same suite).
 */
class StatsRetentionTest extends KernelTestCase
{
    private TestKernel $kernel;

    private TenantKey $tenant;

    protected function host(): Host
    {
        return Host::standard();
    }

    protected function setUp(): void
    {
        $this->tenant = TenantKey::default();
        $this->boot();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function boot(array $config = []): void
    {
        $host         = $this->host();
        $this->kernel = $this->bootKernel($host->bundleConfig($config + ['click_log' => ['rollup' => ['referrer_top_n' => 1]]]), TestKernel::defaultDatabaseUrl(), $host->configure(), $host->kind === Host::STANDARD);
        $host->resetSchema($this->kernel);
        if ($host->kind === Host::STANDARD) {
            $tokens = $this->container()->get('security.token_storage');
            self::assertInstanceOf(TokenStorageInterface::class, $tokens);
            $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('actor-1', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']));
        } else {
            $access = $this->container()->get(TestAccess::class);
            self::assertInstanceOf(TestAccess::class, $access);
            $access->granted = ['default' => [ShortLinkPermission::View, ShortLinkPermission::Manage]];
            $access->actor   = ActorRef::fromString('actor-1');
        }
    }

    public function testRollupIsIdempotentAndStatsAreEqualAcrossTheBoundary(): void
    {
        $link = $this->create('https://example.com');
        $this->click($link, '2026-11-11 08:00:00', referrer: 'a.example', ua: 'firefox', country: 'ES');
        $this->click($link, '2026-11-11 09:00:00', referrer: 'a.example', ua: 'chrome');
        $this->click($link, '2026-11-11 10:00:00', referrer: 'b.example', ua: 'bot', machine: true);
        $this->click($link, '2026-11-11 11:00:00', referrer: 'c.example', ua: 'firefox');
        $this->click($link, '2026-11-09 12:00:00');
        $this->click($link, '2026-11-12 09:00:00', referrer: 'a.example');
        $range = DateRange::of(new DateTimeImmutable('2026-11-08'), new DateTimeImmutable('2026-11-12'));

        $before = $this->snapshot($link, $range);
        self::assertSame('Rolled 3 day(s).', $this->runCommand('nowo:short-link:rollup'));
        $rows = $this->dailyRows();
        self::assertSame('Rolled 2 day(s).', $this->runCommand('nowo:short-link:rollup'), 'the last lag_days (2) are rolled again; older rolled days are not');
        self::assertSame($rows, $this->dailyRows(), 'running it twice gives the same rows');
        self::assertEquals($before, $this->snapshot($link, $range), 'stats are equal before and after the rollup');

        self::assertSame([['2026-11-08', 0, 0], ['2026-11-09', 1, 0], ['2026-11-10', 0, 0], ['2026-11-11', 3, 1], ['2026-11-12', 1, 0]], array_map(static fn (DailyClicks $d): array => [$d->day, $d->clicks, $d->machineClicks], $before['daily']));
        $referrers = $this->stats()->breakdown($this->tenant, $link->id, ClickDimension::Referrer, DateRange::of(new DateTimeImmutable('2026-11-11'), new DateTimeImmutable('2026-11-11')));
        self::assertSame(['a.example:2:0', DimensionClicks::OTHER . ':1:1'], $this->sorted($referrers), 'top 1 referrer + ~other on a rolled day');
        $countries = $this->stats()->breakdown($this->tenant, $link->id, ClickDimension::Country, $range, 1);
        self::assertSame([['', 4, 1]], array_map(static fn (DimensionClicks $d): array => [$d->value, $d->clicks, $d->machineClicks], $countries));

        $this->click($link, '2026-11-11 23:59:59', ua: 'safari');
        $this->runCommand('nowo:short-link:rollup');
        self::assertSame(4, $this->stats()->daily($this->tenant, $link->id, $range)[3]->clicks, 'a late click within lag_days is counted');
        self::assertSame([], $this->stats()->totals($this->tenant, []));
        self::assertSame(0, $this->stats()->totals(TenantKey::fromString('other'), [$link->id])[$link->id->value]->clicks, 'another tenant sees no clicks');
    }

    public function testPurgeDeletesOnlyRolledRawRowsAndExpiredTombstones(): void
    {
        self::assertSame('Rolled 0 day(s).', $this->runCommand('nowo:short-link:rollup'), 'nothing to roll on an empty table');
        $link = $this->create('https://example.com');
        $this->click($link, '2026-07-01 10:00:00');
        $this->click($link, '2026-11-10 10:00:00');
        self::assertSame('Purged 0 click(s), 0 rollup row(s), 0 tombstone(s).', $this->runCommand('nowo:short-link:purge'), 'an unrolled old day is kept');

        $this->runCommand('nowo:short-link:rollup');
        self::assertSame('Purged 1 click(s), 0 rollup row(s), 0 tombstone(s).', $this->runCommand('nowo:short-link:purge'));
        $range = DateRange::of(new DateTimeImmutable('2026-07-01'), new DateTimeImmutable('2026-07-01'));
        self::assertSame(1, $this->stats()->daily($this->tenant, $link->id, $range)[0]->clicks, 'the rollup keeps the purged day');
        $totals = $this->stats()->totals($this->tenant, [$link->id])[$link->id->value];
        self::assertSame(2, $totals->clicks);
        self::assertSame('2026-07-01 00:00:00', $totals->firstClickAt?->format('Y-m-d H:i:s'), 'a purged day is known by its day');
        self::assertSame('2026-11-10 00:00:00', $totals->lastClickAt?->format('Y-m-d H:i:s'));

        $flyer = $this->create('https://example.com/flyer', 'flyer');
        $this->manager()->delete($this->tenant, $flyer->id);
        $this->clock()->modify('+366 days');
        $this->runCommand('nowo:short-link:purge');
        self::assertFalse($this->store()->codeExists('flyer'), 'the code is free after the quarantine');

        $this->boot(['click_log' => ['rollup' => ['retention' => 'P30D']], 'code' => ['quarantine' => null]]);
        $other = $this->create('https://example.com/other');
        $this->click($other, '2026-09-01 10:00:00');
        $this->runCommand('nowo:short-link:rollup');
        self::assertSame('Purged 0 click(s), 4 rollup row(s), 0 tombstone(s).', $this->runCommand('nowo:short-link:purge'), 'rollups older than rollup.retention go');
    }

    public function testTheEmailLogListsTheLinksOfOneSend(): void
    {
        $views = $this->manager()->createMany($this->tenant, [
            new CreateShortLink('https://example.com/b', kind: LinkKind::email(), source: new LinkSource('send-1', 'Button', 1), system: true),
            new CreateShortLink('https://example.com/a', kind: LinkKind::email(), source: new LinkSource('send-1', 'Logo', 0), system: true),
        ]);
        $this->click($views[0], '2026-11-12 09:00:00');
        $this->click($views[0], '2026-11-12 09:30:00', machine: true);

        $log = $this->stats()->bySource($this->tenant, LinkKind::email(), 'send-1');
        self::assertSame(['Logo', 'Button'], array_map(static fn (LinkClickTotals $row): ?string => $row->source?->label, $log));
        self::assertSame([0, 1], [$log[0]->clicks, $log[1]->clicks]);
        self::assertSame(1, $log[1]->machineClicks);
        self::assertSame($views[0]->code->value, $log[1]->code?->value);
        self::assertSame('2026-11-12 09:00:00', $log[1]->firstClickAt?->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-12 09:30:00', $log[1]->lastClickAt?->format('Y-m-d H:i:s'));
        self::assertSame([], $this->stats()->bySource(TenantKey::fromString('other'), LinkKind::email(), 'send-1'));
    }

    public function testErasureBySourceTenantAndActor(): void
    {
        $views = $this->manager()->createMany($this->tenant, [new CreateShortLink('https://example.com/a', kind: LinkKind::email(), source: new LinkSource('send-9', 'Logo', 0), system: true)]);
        $this->click($views[0], '2026-11-11 09:00:00', referrer: 'mail.example', ua: 'firefox', ip: '203.0.113.0/24');
        $this->runCommand('nowo:short-link:rollup');
        $this->click($views[0], '2026-11-12 09:00:00');

        $export = $this->eraser()->exportSource($this->tenant, LinkKind::email(), 'send-9');
        self::assertCount(2, $export);
        self::assertSame(['code' => $views[0]->code->value, 'label' => 'Logo', 'position' => 0, 'clicked_at' => '2026-11-11 09:00:00', 'ip_prefix' => '203.0.113.0/24', 'ua_family' => 'firefox', 'referrer_host' => 'mail.example', 'country' => null, 'machine' => false], $export[0]);
        self::assertSame([], $this->eraser()->exportSource($this->tenant, LinkKind::email(), 'nothing'));
        self::assertSame(0, $this->eraser()->eraseSource($this->tenant, LinkKind::email(), 'nothing'));

        self::assertGreaterThanOrEqual(3, $this->eraser()->eraseSource($this->tenant, LinkKind::email(), 'send-9'));
        self::assertSame([], $this->eraser()->exportSource($this->tenant, LinkKind::email(), 'send-9'));
        self::assertNull($this->manager()->find($this->tenant, $views[0]->id));
        self::assertTrue($this->store()->codeExists($views[0]->code->value));
        self::assertSame(0, $this->stats()->totals($this->tenant, [$views[0]->id])[$views[0]->id->value]->clicks);

        $live = $this->create('https://example.com/live', 'live-link');
        $this->click($live, '2026-11-12 09:00:00');
        self::assertSame(1, $this->eraser()->anonymiseActor(ActorRef::fromString('actor-1')) > 0 ? 1 : 0);
        self::assertNull($this->store()->find($this->tenant, $live->id)?->createdBy);
        self::assertGreaterThanOrEqual(3, $this->eraser()->eraseTenant($this->tenant));
        self::assertNull($this->manager()->find($this->tenant, $live->id));
        self::assertTrue($this->store()->codeExists('live-link'), 'erased tombstones keep their codes');
        self::assertSame('~erased', $this->connection()->fetchOne('SELECT tenant_key FROM ' . $this->host()->tablePrefix() . 'nowo_short_link WHERE code = ?', ['live-link']));

        $http = $this->kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        self::assertSame(404, $http->handle(Request::create('/s/live-link'), HttpKernelInterface::MAIN_REQUEST, false)->getStatusCode(), 'an erased tombstone answers the neutral 404');
        self::assertSame('Checked 0 link(s), disabled 0.', $this->runCommand('nowo:short-link:recheck'));
    }

    public function testRecheckDisablesLinksWhoseTargetIsNowRefused(): void
    {
        $good = $this->create('https://example.com/good');
        $bad  = $this->create('https://example.com/bad');
        $row  = $this->store()->find($this->tenant, $bad->id);
        self::assertNotNull($row);
        $this->store()->update($row->withEdit(TargetUrl::fromString('https://now.bad.example/x'), null, null, new DateTimeImmutable(Host::NOW), null));
        $this->listener()->clear();

        self::assertSame('Checked 2 link(s), disabled 1.', $this->runCommand('nowo:short-link:recheck', ['--batch' => '1']));
        self::assertSame(ShortLinkState::Active, $this->manager()->find($this->tenant, $good->id)?->state);
        $disabled = $this->manager()->find($this->tenant, $bad->id);
        self::assertSame(ShortLinkState::Disabled, $disabled?->state);
        self::assertSame(ShortLinkStateChangedEvent::REASON_RECHECK_REFUSED, $disabled->disabledReason);
        $changes = $this->listener()->of(ShortLinkStateChangedEvent::class);
        self::assertCount(1, $changes);
        self::assertSame(ShortLinkStateChangedEvent::REASON_RECHECK_REFUSED, $changes[0]->reason);
        self::assertSame(GuardStage::Recheck, $this->listener()->of(ShortLinkTargetRefusedEvent::class)[0]->stage);
        $this->connection()->update($this->host()->tablePrefix() . 'nowo_short_link', ['tenant_key' => '~damaged'], ['id' => $good->id->value]);
        self::assertSame('Checked 0 link(s), disabled 0.', $this->runCommand('nowo:short-link:recheck', ['--batch' => 'x']), 'unreadable rows are skipped');
    }

    /**
     * @return array{daily: list<DailyClicks>, referrer: list<string>, ua: list<string>, totals: array{int, int}}
     */
    private function snapshot(ShortLinkView $link, DateRange $range): array
    {
        $totals = $this->stats()->totals($this->tenant, [$link->id], $range)[$link->id->value];

        return [
            'daily'    => $this->stats()->daily($this->tenant, $link->id, $range),
            'referrer' => $this->sorted($this->stats()->breakdown($this->tenant, $link->id, ClickDimension::Referrer, DateRange::of(new DateTimeImmutable('2026-11-12'), new DateTimeImmutable('2026-11-12')))),
            'ua'       => $this->sorted($this->stats()->breakdown($this->tenant, $link->id, ClickDimension::UaFamily, $range)),
            'totals'   => [$totals->clicks, $totals->machineClicks],
        ];
    }

    /**
     * @param list<DimensionClicks> $rows
     *
     * @return list<string>
     */
    private function sorted(array $rows): array
    {
        $out = array_map(static fn (DimensionClicks $d): string => $d->value . ':' . $d->clicks . ':' . $d->machineClicks, $rows);
        sort($out);

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dailyRows(): array
    {
        return $this->connection()->fetchAllAssociative('SELECT link_id, day, dimension, value, clicks, machine_clicks FROM ' . $this->host()->tablePrefix() . 'nowo_short_link_click_daily ORDER BY link_id, day, dimension, value');
    }

    private function click(ShortLinkView $link, string $at, ?string $referrer = null, ?string $ua = null, ?string $country = null, bool $machine = false, ?string $ip = null): void
    {
        $clicks = $this->container()->get(ClickStoreInterface::class);
        self::assertInstanceOf(ClickStoreInterface::class, $clicks);
        $clicks->insert($link->id, $link->tenant, new DateTimeImmutable($at, new DateTimeZone('UTC')), $ip, $ua, $referrer, $country, $machine);
    }

    private function create(string $target, ?string $code = null): ShortLinkView
    {
        return $this->manager()->create($this->tenant, new CreateShortLink($target, customCode: $code));
    }

    /**
     * @param array<string, string> $options
     */
    private function runCommand(string $command, array $options = []): string
    {
        $tester = new CommandTester((new Application($this->kernel))->find($command));
        self::assertSame(0, $tester->execute($options));

        return trim($tester->getDisplay());
    }

    private function container(): ContainerInterface
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    private function manager(): ShortLinkManagerInterface
    {
        $service = $this->container()->get(ShortLinkManagerInterface::class);
        self::assertInstanceOf(ShortLinkManagerInterface::class, $service);

        return $service;
    }

    private function stats(): ShortLinkStatsReaderInterface
    {
        $service = $this->container()->get(ShortLinkStatsReaderInterface::class);
        self::assertInstanceOf(ShortLinkStatsReaderInterface::class, $service);

        return $service;
    }

    private function eraser(): ShortLinkEraserInterface
    {
        $service = $this->container()->get(ShortLinkEraserInterface::class);
        self::assertInstanceOf(ShortLinkEraserInterface::class, $service);

        return $service;
    }

    private function store(): ShortLinkStoreInterface
    {
        $service = $this->container()->get(ShortLinkStoreInterface::class);
        self::assertInstanceOf(ShortLinkStoreInterface::class, $service);

        return $service;
    }

    private function listener(): RecordingListener
    {
        $service = $this->container()->get(RecordingListener::class);
        self::assertInstanceOf(RecordingListener::class, $service);

        return $service;
    }

    private function clock(): MockClock
    {
        $clock = $this->container()->get('clock');
        self::assertInstanceOf(MockClock::class, $clock);

        return $clock;
    }

    private function connection(): Connection
    {
        return $this->host()->connection($this->kernel);
    }
}
