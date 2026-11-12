# Templates

Management pages of ShortLinkBundle on the UiKit page contract (REQ-UI-003). The controller
(`Nowo\ShortLinkBundle\Presentation\Controller\ShortLinkUiController`) builds the view models with
`Nowo\ShortLinkBundle\Presentation\View\ShortLinkPages`; the templates extend a UiKit scaffold and fill data blocks
only. Contract test: `tests/Integration/PageContractTest.php` (UiKit `PageContractTestCase`, strict missing-message
rule).

## Table of contents

- [Pages](#pages)
- [Unique pages](#unique-pages)
- [Overrides](#overrides)
- [Allowed exceptions](#allowed-exceptions)

## Pages

| Page | Template | Scaffold | View model | Data blocks filled |
| --- | --- | --- | --- | --- |
| List | `ui/list.html.twig` | `page/list` | `ListPage` (header: title, intro, create action with Manage; columns code, title, target, kind, state, expires, clicks (30 days, people), created; row actions statistics, and with Manage edit, disable / enable (POST, CSRF-only `FormView` of `ShortLinkActionType`) and delete (POST, same, behind the UiKit `Confirm`); filters `ShortLinkFilterType` (search, state); empty state; `data-testid="short-link-list"`) + `next_href` / `first_href` (keyset pages) | `nowo_ui_list_row`, `nowo_ui_list_after` (`nowo_short_link_pager`), `nowo_short_link_copy` (copy button of a row) |
| Create / edit | `ui/form.html.twig` | `page/form` | `FormPage` (header: title, the quota "12 of 50 links" as intro when the plan has a limit, breadcrumbs, back link; form `ShortLinkType` on the FormKit profile `short_link`; submit / cancel; `data-testid="short-link-new"` / `short-link-edit`) + `quota` (`QuotaUsage` or null). Over the quota the page is body-only (no form) | `nowo_ui_form_body` (`nowo_short_link_form_errors` before the scaffold's child loop; `nowo_short_link_quota_full` when the plan is full) |
| Statistics | `ui/stats.html.twig` | `page/show` | `ShowPage` (header: title, the range as intro, CSV export and edit (Manage) actions, breadcrumbs, back link; stats: people / machine clicks; one `DetailSection` with the short URL, target, title, state badge, expiry, creation; `data-testid="short-link-stats"`) + `link`, `range_form` (`StatsRangeType`), `presets`, `chart` (`DailyChart`), `daily_table` and `breakdowns` (`View\Table`) | `nowo_ui_show_sections` (details: copy button, target link only when http(s) with `rel="noopener noreferrer nofollow"`), `nowo_ui_show_after` (`nowo_short_link_stats_range`, `nowo_short_link_stats_chart`, `nowo_short_link_stats_daily`, `nowo_short_link_stats_breakdowns`) |

Every page passes `bundle: 'NowoShortLinkBundle'` (theme exception `nowo_ui_kit.theme.bundles.NowoShortLinkBundle`)
and `layout: ui.layout_template` (null = `nowo_ui_layout.admin`). Bundle-prefixed semantic classes:
`nowo-short-link-code`, `nowo-short-link-copy`, `nowo-short-link-target`, `nowo-short-link-pager`,
`nowo-short-link-chart`, `nowo-short-link-chart__svg`. The copy buttons use UiKit's clipboard contract
(`data-nowo-ui-clipboard*`, `nowo-ui-clipboard.js`); the bundle ships no JavaScript and no CSS.

The daily chart is a server-side SVG (bars in `currentColor`, machine traffic at 35 % opacity, no script, no inline
style) hidden from assistive technology; the table of the same numbers is always printed after it as the accessible
fallback (contract §11, Q-O5). It moves to UiKit's chart component once UiKit ships one.

## Unique pages

- (none)

## Overrides

A host template that extends `@!NowoShortLink/ui/<page>.html.twig` and overrides one of the data blocks above. Restyle
through `nowo_ui_kit.theme` (slots), never in a bundle template override.

## Allowed exceptions

- (none) — `allowedClasses()` is not overridden in the contract test.
