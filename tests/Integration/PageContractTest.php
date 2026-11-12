<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration;

use DateTimeImmutable;
use Nowo\ShortLinkBundle\Application\QuotaUsage;
use Nowo\ShortLinkBundle\Domain\Model\DailyClicks;
use Nowo\ShortLinkBundle\Domain\Model\DateRange;
use Nowo\ShortLinkBundle\Domain\Model\DimensionClicks;
use Nowo\ShortLinkBundle\Domain\Model\Expiry;
use Nowo\ShortLinkBundle\Domain\Model\LinkClickTotals;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\LinkTitle;
use Nowo\ShortLinkBundle\Domain\Model\ShortCode;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkState;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkView;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Presentation\Form\ShortLinkActionType;
use Nowo\ShortLinkBundle\Presentation\Form\ShortLinkFilterType;
use Nowo\ShortLinkBundle\Presentation\Form\ShortLinkType;
use Nowo\ShortLinkBundle\Presentation\Form\StatsRangeType;
use Nowo\ShortLinkBundle\Presentation\View\DailyChart;
use Nowo\ShortLinkBundle\Presentation\View\ShortLinkPages;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\Host;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\TestKernel;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

use function assert;
use function dirname;
use function http_build_query;

/**
 * REQ-UI-003: every management page extends a UiKit scaffold, fills the canonical blocks, prints framework classes
 * only through theme slots, keeps the CSRF fields of its action forms and has no raw translation keys (UiKit
 * PageContractTestCase). The view models are built by {@see ShortLinkPages} as the controller does; the FormViews come
 * from the bundle's FormKit types in a booted test kernel.
 */
final class PageContractTest extends PageContractTestCase
{
    public static function pageFixtures(): iterable
    {
        $forms   = self::forms();
        $pages   = new ShortLinkPages(static fn (string $route, array $params = []): string => '/t/acme/' . $route . ($params === [] ? '' : '?' . http_build_query($params)));
        $links   = [self::link('aB3xY9k', ShortLinkState::Active, 'Launch <b>page</b>'), self::link('spring-sale', ShortLinkState::Disabled, null), self::link('old-link', ShortLinkState::Expired, 'Old')];
        $totals  = [$links[0]->id->value => new LinkClickTotals($links[0]->id, 12, 3)];
        $actions = [];
        foreach (['disable', 'enable', 'delete'] as $action) {
            $actions[$action] = $forms->createNamed('', ShortLinkActionType::class, null, ['csrf_token_id' => 'nowo_short_link_' . $action])->createView();
        }
        $filters = $forms->createNamed('', ShortLinkFilterType::class)->createView();

        yield 'list (manager)' => new PageFixture('@NowoShortLink/ui/list.html.twig', ['page' => $pages->list($links, $totals, $filters, false, $actions), 'next_href' => '/t/acme/next', 'first_href' => '/t/acme/first']);
        yield 'list (viewer, filtered)' => new PageFixture('@NowoShortLink/ui/list.html.twig', ['page' => $pages->list($links, [], $filters, true, null), 'next_href' => null, 'first_href' => null]);
        yield 'list (empty)' => new PageFixture('@NowoShortLink/ui/list.html.twig', ['page' => $pages->list([], [], $filters, false, $actions), 'next_href' => null, 'first_href' => null]);

        $create = $forms->create(ShortLinkType::class, null, ['custom_code' => true]);
        yield 'create' => new PageFixture('@NowoShortLink/ui/form.html.twig', ['page' => $pages->form($create->createView(), null, new QuotaUsage(12, 50)), 'quota' => new QuotaUsage(12, 50)]);
        yield 'create (quota full)' => new PageFixture('@NowoShortLink/ui/form.html.twig', ['page' => $pages->form(null, null, new QuotaUsage(50, 50)), 'quota' => new QuotaUsage(50, 50)]);
        $edit = $forms->create(ShortLinkType::class, ['target' => 'https://example.com/a', 'title' => 'Launch', 'expiresAt' => null]);
        yield 'edit' => new PageFixture('@NowoShortLink/ui/form.html.twig', ['page' => $pages->form($edit->createView(), $links[0], null), 'quota' => null]);

        $range = DateRange::of(new DateTimeImmutable('2026-11-10'), new DateTimeImmutable('2026-11-12'));
        $daily = [new DailyClicks('2026-11-10', 0, 0), new DailyClicks('2026-11-11', 4, 1), new DailyClicks('2026-11-12', 2, 0)];
        foreach (['manager' => true, 'viewer' => false] as $profile => $manage) {
            yield 'stats (' . $profile . ')' => new PageFixture('@NowoShortLink/ui/stats.html.twig', [
                'page'        => $pages->stats($links[$manage ? 0 : 1], $range, $daily, $manage, '/t/acme/csv'),
                'link'        => $links[$manage ? 0 : 1],
                'range_form'  => $forms->createNamed('', StatsRangeType::class)->createView(),
                'presets'     => [['days' => 7, 'href' => '/t/acme/7', 'active' => false], ['days' => 30, 'href' => '/t/acme/30', 'active' => true]],
                'chart'       => new DailyChart($daily),
                'daily_table' => $pages->dailyTable($daily),
                'breakdowns'  => [
                    'referrer' => $pages->breakdownTable('referrer', [new DimensionClicks('news.example', 3, 0), new DimensionClicks('', 1, 1), new DimensionClicks(DimensionClicks::OTHER, 2, 0)]),
                    'country'  => $pages->breakdownTable('country', []),
                    'ua'       => $pages->breakdownTable('ua', [new DimensionClicks('firefox', 4, 0), new DimensionClicks('preview', 0, 1)]),
                ],
            ]);
        }
    }

    protected static function templatePaths(): array
    {
        $views = dirname(__DIR__, 2) . '/src/Resources/views';

        return ['NowoShortLink' => $views, '!NowoShortLink' => $views];
    }

    protected static function translationDirs(): array
    {
        return [dirname(__DIR__, 2) . '/src/Resources/translations'];
    }

    protected static function templatesDocument(): string
    {
        return dirname(__DIR__, 2) . '/docs/TEMPLATES.md';
    }

    protected static function strictMissingTranslations(): bool
    {
        return true;
    }

    private static function link(string $code, ShortLinkState $state, ?string $title): ShortLinkView
    {
        $now = new DateTimeImmutable('2026-11-12 10:00:00');

        return new ShortLinkView(
            ShortLinkId::fromString('0190a6d0-0000-7000-8000-0000000000' . ($state === ShortLinkState::Active ? '01' : ($state === ShortLinkState::Disabled ? '02' : '03'))),
            TenantKey::default(),
            ShortCode::fromStored($code, $code === 'spring-sale' || $code === 'old-link'),
            TargetUrl::fromString('https://example.com/' . $code . '?utm=x'),
            LinkTitle::fromString($title),
            LinkKind::manual(),
            null,
            null,
            $state,
            $state === ShortLinkState::Expired ? Expiry::fromDateTime(new DateTimeImmutable('2026-11-01 00:00:00')) : null,
            $state === ShortLinkState::Disabled ? 'manual' : null,
            $now,
            $now,
            null,
            null,
            'https://links.example/s/' . $code,
        );
    }

    /**
     * The form factory of a booted test kernel (FormKit types, CSRF on a mock session).
     */
    private static function forms(): FormFactoryInterface
    {
        $host   = Host::ddd();
        $kernel = new TestKernel('page_contract', $host->bundleConfig(['ui' => ['enabled' => true]]), null, $host->configure(), false, true);
        $kernel->boot();
        $services = $kernel->getContainer()->get('test.service_container');
        assert($services instanceof ContainerInterface);
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = $services->get('request_stack');
        assert($stack instanceof RequestStack);
        $stack->push($request);
        $forms = $services->get('form.factory');
        assert($forms instanceof FormFactoryInterface);

        return $forms;
    }
}
