<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit\Presentation;

use LogicException;
use Nowo\ShortLinkBundle\Application\Default\ConfigQuota;
use Nowo\ShortLinkBundle\Application\Default\UnlimitedCodeTakenLimiter;
use Nowo\ShortLinkBundle\Application\ManagerSettings;
use Nowo\ShortLinkBundle\Application\QuotaReader;
use Nowo\ShortLinkBundle\Application\QuotaUsage;
use Nowo\ShortLinkBundle\DependencyInjection\Compiler\ShortLinkCompilerPass;
use Nowo\ShortLinkBundle\DependencyInjection\NowoShortLinkExtension;
use Nowo\ShortLinkBundle\Domain\Model\ActorRef;
use Nowo\ShortLinkBundle\Domain\Model\DailyClicks;
use Nowo\ShortLinkBundle\Domain\Model\DimensionClicks;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Infrastructure\RateLimit\RateLimiterCodeTakenLimiter;
use Nowo\ShortLinkBundle\Presentation\Csv\StatsCsv;
use Nowo\ShortLinkBundle\Presentation\Routing\UiRouteLoader;
use Nowo\ShortLinkBundle\Presentation\View\DailyChart;
use Nowo\ShortLinkBundle\Tests\Fixtures\ScriptedStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\Routing\Route;

use function file_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * The small pieces of the management UI: CSV neutralisation, chart geometry, the route loader, the quota reader, the
 * code-taken limiters and the UI dependency checks of the container.
 */
final class UiPiecesTest extends TestCase
{
    public function testCsvCellsStartingLikeAFormulaAreNeutralised(): void
    {
        foreach (['=1+1', '+1', '-1', '@SUM(A1)', "\tx", "\rx"] as $value) {
            self::assertSame("'" . $value, StatsCsv::cell($value));
        }
        foreach (['', 'news.example', '2026-11-12', '12', "'quoted"] as $value) {
            self::assertSame($value, StatsCsv::cell($value));
        }

        $rows = StatsCsv::rows([new DailyClicks('2026-11-12', 2, 1)], ['referrer' => [new DimensionClicks('=HYPERLINK("x")', 1, 0)], 'country' => []]);
        self::assertSame([
            ['day', 'clicks', 'machine_clicks'],
            ['2026-11-12', '2', '1'],
            [],
            ['referrer', 'clicks', 'machine_clicks'],
            ["'=HYPERLINK(\"x\")", '1', '0'],
            [],
            ['country', 'clicks', 'machine_clicks'],
        ], $rows);
        self::assertSame([['country', 'clicks', 'machine_clicks']], StatsCsv::rows(null, ['country' => []]));
    }

    public function testCsvIsWrittenWithAnOptionalBomAndAnUnwritableTargetWritesNothing(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'csv');
        StatsCsv::write([['a', 'b c'], ['1', '2']], true, $file);
        self::assertSame("\u{FEFF}a,\"b c\"\n1,2\n", file_get_contents($file));
        StatsCsv::write([['a']], false, $file);
        self::assertSame("a\n", file_get_contents($file));
        unlink($file);

        StatsCsv::write([['a']], false, sys_get_temp_dir() . '/no-such-dir/x/y.csv');
        $this->addToAssertionCount(1);
    }

    public function testTheChartScalesBarsToTheHighestDay(): void
    {
        $chart = new DailyChart([new DailyClicks('2026-11-11', 3, 1), new DailyClicks('2026-11-12', 0, 0)]);
        self::assertSame(4, $chart->max);
        self::assertSame(24, $chart->width);
        self::assertSame(['day' => '2026-11-11', 'clicks' => 3, 'machine' => 1, 'x' => 0, 'y' => 30.0, 'height' => 90.0, 'machineY' => 0.0, 'machineHeight' => 30.0], $chart->bars[0]);
        self::assertSame(12, $chart->bars[1]['x']);
        self::assertSame(0.0, $chart->bars[1]['height']);

        $empty = new DailyChart([]);
        self::assertSame(0, $empty->max);
        self::assertSame(DailyChart::STEP, $empty->width);
        self::assertSame([], $empty->bars);
    }

    public function testTheRouteLoaderAddsTheUiRoutesOnlyWhenEnabled(): void
    {
        $disabled = new UiRouteLoader(false, '/short-links');
        self::assertTrue($disabled->supports('.', UiRouteLoader::TYPE));
        self::assertFalse($disabled->supports('.', 'yaml'));
        self::assertCount(0, $disabled->load('.', UiRouteLoader::TYPE));

        $routes = (new UiRouteLoader(true, '/links'))->load('.', UiRouteLoader::TYPE);
        self::assertCount(8, $routes);
        $route = static fn (string $name): Route => $routes->get($name) ?? throw new LogicException('Missing route ' . $name);
        self::assertSame('/links', $route('nowo_short_link_index')->getPath());
        self::assertSame(['POST'], $route('nowo_short_link_delete')->getMethods());
        self::assertSame(['GET'], $route('nowo_short_link_stats_csv')->getMethods());
        self::assertSame('/links/{id}/stats.csv', $route('nowo_short_link_stats_csv')->getPath());
        self::assertSame(UiRouteLoader::ID_REQUIREMENT, $route('nowo_short_link_edit')->getRequirement('id'));
    }

    public function testTheQuotaReaderAnswersOnlyForCountedKindsWithALimit(): void
    {
        $reader = new QuotaReader(new ScriptedStore(), new ConfigQuota(null), new ManagerSettings());
        self::assertNull($reader->usage(TenantKey::default(), LinkKind::manual()), 'no limit');
        $reader = new QuotaReader(new ScriptedStore(), new ConfigQuota(3), new ManagerSettings());
        self::assertNull($reader->usage(TenantKey::default(), LinkKind::email()), 'e-mail links are not counted');
        $usage = $reader->usage(TenantKey::default(), LinkKind::manual());
        self::assertInstanceOf(QuotaUsage::class, $usage);
        self::assertSame(3, $usage->limit);
        self::assertFalse($usage->isFull());
        self::assertTrue((new QuotaUsage(3, 3))->isFull());
    }

    public function testTheCodeTakenLimiterCountsPerActor(): void
    {
        $limiter = new RateLimiterCodeTakenLimiter(new RateLimiterFactory(['id' => 'test', 'policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 minute'], new CacheStorage(new ArrayAdapter())));
        $alice   = ActorRef::fromString('alice');
        self::assertTrue($limiter->allows($alice));
        $limiter->hit($alice);
        self::assertTrue($limiter->allows($alice));
        $limiter->hit($alice);
        self::assertFalse($limiter->allows($alice));
        self::assertTrue($limiter->allows(ActorRef::fromString('bob')), 'per actor');
        self::assertTrue($limiter->allows(null), 'anonymous callers share one window of their own');

        $unlimited = new UnlimitedCodeTakenLimiter();
        $unlimited->hit($alice);
        self::assertTrue($unlimited->allows($alice));
    }

    public function testTheUiNeedsUiKitFormKitAndTheFrameworkServices(): void
    {
        $container = $this->container();
        try {
            (new ShortLinkCompilerPass())->process($container);
            self::fail('UiKit is required.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('nowo-tech/ui-kit-bundle', $exception->getMessage());
        }

        foreach (['nowo_ui_kit', 'nowo_form_kit'] as $alias) {
            $container->registerExtension(new class($alias) extends Extension {
                public function __construct(private readonly string $alias)
                {
                }

                public function load(array $configs, ContainerBuilder $container): void
                {
                }

                public function getAlias(): string
                {
                    return $this->alias;
                }
            });
        }
        try {
            (new ShortLinkCompilerPass())->process($container);
            self::fail('Forms are required.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('framework.form', $exception->getMessage());
        }
    }

    public function testAHostFormKitProfileIsKept(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'nowo_form_kit';
            }
        });
        $container->prependExtensionConfig('nowo_form_kit', ['profiles' => ['short_link' => ['alias' => 'mine']]]);
        (new NowoShortLinkExtension())->prepend($container);
        self::assertSame([['profiles' => ['short_link' => ['alias' => 'mine']]]], $container->getExtensionConfig('nowo_form_kit'));

        $fresh = new ContainerBuilder();
        $fresh->registerExtension($container->getExtension('nowo_form_kit'));
        (new NowoShortLinkExtension())->prepend($fresh);
        $seeded = $fresh->getExtensionConfig('nowo_form_kit')[0] ?? null;
        self::assertIsArray($seeded);
        self::assertIsArray($seeded['profiles'] ?? null);
        self::assertSame(['alias' => 'short_link', 'translation_domain' => 'NowoShortLinkBundle', 'convention_requires_translation' => true, 'defaults' => ['attr' => ['class' => 'nowo-ui-input'], 'row_attr' => []]], $seeded['profiles']['short_link'] ?? null);
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register('doctrine');
        $container->setParameter('nowo_short_link.click_write_mode', null);
        $container->setParameter('nowo_short_link.ui_enabled', true);

        return $container;
    }
}
