<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Presentation\View;

use Closure;
use Nowo\ShortLinkBundle\Application\QuotaUsage;
use Nowo\ShortLinkBundle\Domain\Model\DailyClicks;
use Nowo\ShortLinkBundle\Domain\Model\DateRange;
use Nowo\ShortLinkBundle\Domain\Model\DimensionClicks;
use Nowo\ShortLinkBundle\Domain\Model\Expiry;
use Nowo\ShortLinkBundle\Domain\Model\LinkClickTotals;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkState;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkView;
use Nowo\ShortLinkBundle\NowoShortLinkBundle;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\BackLink;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\Crumb;
use Nowo\UiKitBundle\View\DetailItem;
use Nowo\UiKitBundle\View\DetailSection;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FilterField;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\ShowPage;
use Nowo\UiKitBundle\View\Stat;
use Nowo\UiKitBundle\View\Table;
use Symfony\Component\Form\FormView;

use function array_map;
use function array_sum;
use function assert;

/**
 * Builds the UiKit view models of the management pages (REQ-UI-003): list (list scaffold), create / edit (form
 * scaffold) and statistics (show scaffold). Plain data in, immutable view models out; built per request by the
 * controller with a URL function that keeps the host's tenant route parameter (worker safe, nothing kept).
 *
 * @internal
 */
final readonly class ShortLinkPages
{
    /** Translation domain of every label. */
    public const DOMAIN = NowoShortLinkBundle::TRANSLATION_DOMAIN;

    /** Bundle name for per-bundle theme exceptions (`nowo_ui_kit.theme.bundles.NowoShortLinkBundle`). */
    public const BUNDLE = 'NowoShortLinkBundle';

    /** Breakdown sections of the statistics page: dimension => title key. */
    public const BREAKDOWNS = ['referrer' => 'ui.stats.referrers', 'country' => 'ui.stats.countries', 'ua' => 'ui.stats.browsers'];

    /**
     * @param Closure(string, array<string, string>=): string $url route name + parameters → URL (tenant parameter kept)
     * @param string|null $layout `ui.layout_template`; null = the UiKit admin layout
     */
    public function __construct(private Closure $url, private ?string $layout = null)
    {
    }

    /**
     * The list page; one {@see ShortLinkRow} per link.
     *
     * @param list<ShortLinkView> $links
     * @param array<string, LinkClickTotals> $totals keyed by link id
     * @param array{disable: FormView, enable: FormView, delete: FormView}|null $actionForms null = view only
     */
    public function list(array $links, array $totals, FormView $filters, bool $filtered, ?array $actionForms): ListPage
    {
        $rows = [];
        foreach ($links as $link) {
            $total  = $totals[$link->id->value] ?? null;
            $rows[] = new ShortLinkRow(
                $link,
                $total->clicks ?? 0,
                $total->machineClicks ?? 0,
                $this->link('nowo_short_link_stats', $link),
                $this->link('nowo_short_link_edit', $link),
            );
        }
        $create   = $actionForms === null ? [] : [Action::create(($this->url)('nowo_short_link_new'), $this->label('ui.action.create'))];
        $indexUrl = ($this->url)('nowo_short_link_index');

        return new ListPage(
            new PageHeader($this->label('ui.list.title'), $this->label('ui.list.intro'), $create),
            [
                new Column('code', $this->label('ui.column.code'), required: true, phone: 'primary'),
                new Column('title', $this->label('ui.column.title'), priority: 2),
                new Column('target', $this->label('ui.column.target'), required: true),
                new Column('kind', $this->label('ui.column.kind'), priority: 3),
                new Column('state', $this->label('ui.column.state'), required: true),
                new Column('expires', $this->label('ui.column.expires'), priority: 3),
                new Column('clicks', $this->label('ui.column.clicks'), align: 'end'),
                new Column('created', $this->label('ui.column.created'), priority: 2),
            ],
            $rows,
            $filters,
            function (mixed $row) use ($actionForms): array {
                assert($row instanceof ShortLinkRow);

                return $this->rowActions($row, $actionForms);
            },
            null,
            new EmptyState($this->label($filtered ? 'ui.list.empty_filtered' : 'ui.list.empty'), null, $create[0] ?? null),
            $filtered ? $indexUrl : null,
            caption: $this->label('ui.list.caption'),
            bundle: self::BUNDLE,
            layout: $this->layout,
            attributes: ['data-testid' => 'short-link-list'],
            filterFields: [new FilterField('q', width: 'lg'), new FilterField('state', width: 'sm')],
            filtersSubmit: new Action('filter', $this->label('ui.action.filter')),
            captionVisible: false,
        );
    }

    /**
     * The create / edit page. Over the quota the form is not shown (body-only page with the quota message).
     */
    public function form(?FormView $form, ?ShortLinkView $link, ?QuotaUsage $quota): FormPage
    {
        $index = ($this->url)('nowo_short_link_index');
        $title = $link instanceof ShortLinkView ? $this->label('ui.form.edit_title', ['%code%' => $link->code->value]) : $this->label('ui.form.create_title');

        return new FormPage(
            new PageHeader(
                $title,
                $quota instanceof QuotaUsage ? $this->label('ui.quota.usage', ['%used%' => $quota->used, '%limit%' => $quota->limit]) : null,
                [],
                [new Crumb($this->label('ui.list.title'), $index), new Crumb($title)],
                back: new BackLink($index, $this->label('ui.action.back'), 'short-link-back'),
            ),
            $form,
            $this->label($link instanceof ShortLinkView ? 'ui.action.save' : 'ui.action.create'),
            $index,
            $this->label('ui.action.cancel'),
            bundle: self::BUNDLE,
            layout: $this->layout,
            attributes: ['data-testid' => $link instanceof ShortLinkView ? 'short-link-edit' : 'short-link-new'],
            formAttributes: ['novalidate' => true],
        );
    }

    /**
     * The statistics page: totals as key figures, the link details, then (template) the range form, the chart with its
     * table and the breakdown tables.
     *
     * @param list<DailyClicks> $daily
     */
    public function stats(ShortLinkView $link, DateRange $range, array $daily, bool $canManage, string $csvHref): ShowPage
    {
        $index  = ($this->url)('nowo_short_link_index');
        $title  = $this->label('ui.stats.title', ['%code%' => $link->code->value]);
        $people = array_sum(array_map(static fn (DailyClicks $day): int => $day->clicks, $daily));
        $bots   = array_sum(array_map(static fn (DailyClicks $day): int => $day->machineClicks, $daily));
        $range  = ['%from%' => $range->from->format('Y-m-d'), '%to%' => $range->to->format('Y-m-d')];

        return new ShowPage(
            new PageHeader(
                $title,
                $this->label('ui.stats.range', $range),
                [
                    new Action('export', $this->label('ui.action.csv'), $csvHref, attributes: ['data-testid' => 'short-link-csv']),
                    ...($canManage ? [Action::edit($this->link('nowo_short_link_edit', $link), $this->label('ui.action.edit'))] : []),
                ],
                [new Crumb($this->label('ui.list.title'), $index), new Crumb($title)],
                back: new BackLink($index, $this->label('ui.action.back'), 'short-link-back'),
            ),
            [new DetailSection($this->label('ui.stats.link'), [
                new DetailItem($this->label('ui.column.short_url'), $link->shortUrl),
                new DetailItem($this->label('ui.column.target'), $link->target->toString()),
                new DetailItem($this->label('ui.column.title'), $link->title?->value),
                new DetailItem($this->label('ui.column.state'), $this->label('ui.state.' . $link->state->value), badge: self::stateBadge($link->state)),
                new DetailItem($this->label('ui.column.expires'), $link->expiry instanceof Expiry ? $link->expiry->at->format('Y-m-d H:i') . ' UTC' : null),
                new DetailItem($this->label('ui.column.created'), $link->createdAt->format('Y-m-d H:i') . ' UTC'),
            ], 'short-link-details')],
            [],
            self::BUNDLE,
            $this->layout,
            ['data-testid' => 'short-link-stats'],
            [
                new Stat($this->label('ui.stats.clicks'), $people, null, 'info', attributes: ['data-testid' => 'short-link-clicks']),
                new Stat($this->label('ui.stats.machine_clicks'), $bots, $this->label('ui.stats.machine_hint')),
            ],
        );
    }

    /**
     * Daily table of the statistics page (the accessible fallback of the chart, always printed).
     *
     * @param list<DailyClicks> $daily
     */
    public function dailyTable(array $daily): Table
    {
        return new Table(
            [
                new Column('day', $this->label('ui.stats.day')),
                new Column('clicks', $this->label('ui.stats.clicks'), align: 'end'),
                new Column('machineClicks', $this->label('ui.stats.machine_clicks'), align: 'end'),
            ],
            $daily,
            caption: $this->label('ui.stats.daily'),
            responsive: 'scroll',
            bundle: self::BUNDLE,
            attributes: ['data-testid' => 'short-link-daily'],
        );
    }

    /**
     * One breakdown table (referrer hosts, countries or browser families).
     *
     * @param list<DimensionClicks> $values
     */
    public function breakdownTable(string $dimension, array $values): Table
    {
        return new Table(
            [
                new Column('value', $this->label('ui.stats.value_' . $dimension)),
                new Column('clicks', $this->label('ui.stats.clicks'), align: 'end'),
                new Column('machineClicks', $this->label('ui.stats.machine_clicks'), align: 'end'),
            ],
            $values,
            caption: $this->label(self::BREAKDOWNS[$dimension] ?? 'ui.stats.daily'),
            responsive: 'scroll',
            bundle: self::BUNDLE,
            attributes: ['data-testid' => 'short-link-breakdown-' . $dimension],
        );
    }

    /**
     * Badge tone of a state (list cells and the details).
     */
    public static function stateBadge(ShortLinkState $state): string
    {
        return match ($state) {
            ShortLinkState::Active   => 'success',
            ShortLinkState::Disabled => 'warning',
            default                  => 'neutral',
        };
    }

    /**
     * Row actions: statistics for everyone; edit, disable / enable and delete (confirm dialog) with Manage.
     *
     * @param array{disable: FormView, enable: FormView, delete: FormView}|null $forms
     *
     * @return list<Action>
     */
    private function rowActions(ShortLinkRow $row, ?array $forms): array
    {
        $link    = $row->link;
        $actions = [new Action('view', $this->label('ui.action.stats'), $row->stats, attributes: ['data-testid' => 'short-link-stats-' . $link->code->value])];
        if ($forms === null) {
            return $actions;
        }
        $actions[] = Action::edit($row->edit, $this->label('ui.action.edit'));
        $actions[] = $link->state === ShortLinkState::Disabled
            ? new Action('enable', $this->label('ui.action.enable'), $this->link('nowo_short_link_enable', $link), method: 'POST', form: $forms['enable'])
            : new Action('disable', $this->label('ui.action.disable'), $this->link('nowo_short_link_disable', $link), method: 'POST', form: $forms['disable']);
        $actions[] = Action::delete(
            $this->link('nowo_short_link_delete', $link),
            null,
            new Confirm(
                $this->label('ui.delete.title', ['%code%' => $link->code->value]),
                $this->label('ui.delete.message'),
                $this->label('ui.action.delete'),
            ),
            $this->label('ui.action.delete'),
            $forms['delete'],
        );

        return $actions;
    }

    private function link(string $route, ShortLinkView $link): string
    {
        return ($this->url)($route, ['id' => $link->id->value]);
    }

    /**
     * @param array<string, int|string> $params
     */
    private function label(string $key, array $params = []): Label
    {
        return new Label($key, self::DOMAIN, $params);
    }
}
