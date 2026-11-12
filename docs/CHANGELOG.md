# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

S4: the management UI (target 0.2.0).

### Added

- Management pages on UiKit scaffolds + FormKit forms (`ui.enabled: true`; routes
  `@NowoShortLinkBundle/Resources/config/routes/ui.php`, loader type `nowo_short_link_ui`, empty while the UI is
  disabled): list (search, state filter, keyset pages of `ui.page_size`, clicks of the last 30 days, copy button),
  create / edit (target, optional custom code on create, title, expiry; quota "12 of 50 links", form replaced by a
  message when the plan is full), disable / enable / delete (POST, one CSRF token per action, delete behind the UiKit
  confirm dialog), statistics (people / machine totals, server-side SVG daily chart with its table as the accessible
  fallback, top referrer hosts, countries, browser families, 7 / 30 / 90-day presets and a custom range) and the CSV
  export (`ui.csv_max_days`, `?dimension=`, `?excel=1` BOM).
- Every page resolves the tenant through the tenant-context port (none → 404) and checks the access port (View for the
  pages, Manage for every change); ids of another tenant answer 404. `ui.tenant_route_parameter` keeps the host prefix
  parameter (Beacon: `uuid`) in every UI link. New `ui.layout_template` (null = the UiKit admin layout).
- FormKit profile `short_link` prepended by the bundle (translation domain `NowoShortLinkBundle`); a host profile of
  the same name wins.
- Translations of the UI in en, es, fr, it, pt, de and nl; Twig blocks for overrides (`docs/TEMPLATES.md`); UiKit page
  contract test.
- Audit L7: per-actor limiter on "code not available" answers of custom codes (`code.taken_rate_limit`, default
  10 per minute, stored in `redirect.rate_limit.cache_pool`; port `CodeTakenLimiterInterface`, new
  `CodeAttemptsExceededException`). Over the limit no custom code is looked up until the window ends; `system`
  callers and random codes are never limited.
- `twig/string-extra` is now a requirement (REQ-TWIG-004 baseline; the bundle ships Twig pages).
- Demo: the UI under `/short-links` (demo access port, demo shell as `ui.layout_template`); Playwright e2e
  (`make test-e2e`, workflow `e2e.yml` on every pull request) and README screenshots (`make demo-screenshots`).

### Changed

- The recheck evaluates its abort threshold after every link once `target.recheck_min_sample` is reached (not only at
  the end of a batch), so a resolver outage stops the run at once; the non-DNS refusals found before the abort are
  still disabled first. The description of `target.recheck_max_unresolvable_percent` says that `0` aborts on any
  unresolvable target only once the minimum sample was checked (audit NY Info).
- Contract §10: rollups are kept while the link exists and deleted with it when its tombstone is purged after
  `code.quarantine`.

### Fixed

- The list search (code, title, target host) is case-insensitive on every database (it was case-sensitive on
  PostgreSQL, and for codes on MySQL / MariaDB).

### Security

- CSV cells starting with `=`, `+`, `-`, `@`, a tab or a carriage return are prefixed with `'`; target URLs are printed
  escaped and linked only when http(s), with `rel="noopener noreferrer nofollow"`. The forms never map `system` or
  `kind` (extra fields are refused); people only create `manual` links.

## [0.1.0] - 2026-11-12

First release: slices S1 (domain + persistence), S2 (public redirect) and S3 (statistics, retention, recheck, GDPR), audited (NY, NY re-verification). The admin UI (S4) follows in 0.2.0.

### Fixed (audit NY re-verification)

- N1: the recheck abort threshold is evaluated over the whole run, only once `target.recheck_min_sample` (default 20)
  links were checked, and the other refusals of the batch (denylist, private host, self chain) are applied before it
  aborts; `recheck_max_unresolvable_percent: 0` aborts on any unresolvable target.
- N2: erasures run in a retry-safe order (mark deleted keeping the source ref, delete clicks and rollups, blank last);
  `sharesConnection()` also recognises the same connection named explicitly.
- N3: erasures delete clicks once more after blanking (a click recorded between the liveness check and its insert).
- I1: inline click modes (sync, terminate) skip the liveness check; Messenger clicks keep it.
- I2: `exportSource()` also returns the send's links (target, title, created_at); purging a tombstone deletes its
  clicks and rollups.
- I3: a deadlock during the rollup skips the day with a warning, like a unique violation.

### Fixed (audit NY)

- M1: the recheck disables a link with a narrow update (still active, not deleted, same target and tenant) and
  dispatches its events only when that row changed: it can no longer resurrect a deleted or erased link nor revert an
  edit made during the run.
- M2: DNS failures never disable links on recheck: unresolvable targets are skipped with a warning, a batch with more
  than `target.recheck_max_unresolvable_percent` (default 20) unresolvable targets aborts the run, and `--dry-run`
  reports without writing.
- M3: the rollup never recomputes an already rolled day at or before the raw retention cutoff; the configuration
  refuses a `click_log.retention` shorter than `rollup.lag_days` + 1 days and a `rollup.retention` shorter than
  `click_log.retention`.
- M4: erasures tombstone first and delete clicks after, in one transaction when both tables share a connection; the
  recorder (and so the Messenger handler) drops a click whose link is missing, deleted, erased or in another tenant.
- L1: the recheck cursor moves past unreadable rows, so it never stops early.
- L2: concurrent rollups are serialised with symfony/lock (or a concurrently written day is skipped); new index
  `idx_short_link_daily_day` on the daily table.
- L3: `eraseSource()` blanks the tombstones (target, title, source, metadata, actors) like `eraseTenant()`.
- L4: `exportSource()` returns `['clicks' => …, 'daily' => …]`, the rollup rows included.
- The self-chain guard collapses empty path segments (`//s/abc` behind slash-merging proxies).

### Changed

- Audit NW follow-ups on S1: the self-chain guard percent-decodes the whole path (as the router does, `%2F` included) and removes dot segments
  before comparing, counts the `public_base_url` host as own and always includes `redirect.path_prefix` + `/`; every
  anchored pattern uses the `D` modifier (a trailing newline never passes); counted creates (plan limit) inside an open
  database transaction are refused, so the tenant lock always covers the commit; `list()` only returns kinds shown in the
  UI unless the query is `system`; targets refuse invisible / bidirectional format characters and percent-encode
  non-ASCII bytes (the stored URL and `Location` are pure ASCII).

### Added

- S3 statistics and retention (contract §5.3, §10): `ShortLinkStatsReaderInterface` (`daily`, `breakdown` by referrer
  host / country / browser family, `totals`, `bySource` for the e-mail log) exact across the rollup / raw boundary;
  commands `nowo:short-link:rollup` (idempotent, `lag_days`, referrer top N + `~other`), `nowo:short-link:purge` (raw
  clicks of rolled days older than `click_log.retention`, rollups older than `rollup.retention`, tombstones older than
  `code.quarantine`) and `nowo:short-link:recheck` (save guard with DNS over active links, refused ones disabled with
  `recheck_refused` + events); `ShortLinkEraserInterface` (`eraseTenant` → blanked `~erased` tombstones,
  `eraseSource`, `anonymiseActor`, `exportSource`).

- S2 public redirect (contract §7): route `nowo_short_link_redirect` `GET|HEAD {redirect.path_prefix}/{code}` (import
  `@NowoShortLinkBundle/Resources/config/routes/redirect.php`), stateless, step order of §7.1, protective headers on every
  answer, one neutral 404 (byte-identical for unknown, disabled, expired, deleted and refused links) and a 429 variant
  with `Retry-After`, per-IP miss / hit limiters on a configurable cache pool (`redirect_unlogged` or `block` over the hit
  limit), redirect guard without DNS.
- Click log: truncated IP (/24, /48), browser family (built-in classifier: browsers, bots, link previews, e-mail
  scanners), referrer host, optional country (`click_log.country_resolver`, local lookups only), machine flag, `Sec-GPC`
  honoured (count only), written after the response (`terminate`), through Messenger (`RecordShortLinkClick`, never the
  raw IP) or synchronously; failures never break the redirect; `ShortLinkClickedEvent`.
- Audit NW fixes on S2: limiter key per IPv4 address or IPv6 network (`redirect.rate_limit.ipv6_prefix`, default 64;
  IPv4-mapped and 6to4 fold to IPv4); click / refused-event listeners and log handlers can no longer change the answer
  or stop a worker (refused event deferred to `kernel.terminate`); the redirect lookup selects only the columns it needs
  and decides the state from the raw row (unreadable rows answer 404 with a warning); 6to4 and Teredo IP prefixes; codes
  of any length reach the neutral 404; `honour_gpc: false` keeps the configured columns; `tenancy.context` without
  `access.provider` is refused at build.

- S1 domain + persistence (contract §3, §5.1, §6, §8, §12): value objects (`ShortLinkId`, `TenantKey`, `ShortCode`,
  `TargetUrl`, `LinkKind`, `LinkSource`, `LinkMetadata`, `ActorRef`, `LinkTitle`, `Expiry`, read models), enums, ports
  with defaults (tenant context, access, quota, target guard, denylist, reserved codes, blocklist, id generator, tenant
  lock), events dispatched after commit, `ShortLinkManagerInterface` (`create`, `createMany` with dedupe by send ref +
  position, `update`, `disable`, `enable`, `delete` as tombstone, `find`, `list` with keyset pages, `shortUrl`).
- Target guard chain: self-chain check (`own_hosts` + `self_paths`), OutboundUrlGuard (private / loopback / link-local /
  CGNAT / metadata refused, DNS on save when `save_resolve_dns`), denylist port.
- Persistence: DBAL store (never flushes the host EntityManager), `ShortLinkRecord` mapped in XML (default) with an
  attribute twin, table / index prefix, `utf8mb4_bin` on the code and identifier columns (MySQL / MariaDB), the three click
  tables created by a `postGenerateSchema` listener, after-commit DBAL middleware shared with ApiTokenBundle's design.
- Quota exact under concurrency with symfony/lock (tenant lock) when the host has a lock factory.

- S0 scaffold (contract v1.0 for spec 230 Block A, [CONTRACT.md](CONTRACT.md)): the bundle class `NowoShortLinkBundle`,
  the strict configuration tree `nowo_short_link` with every key of the contract (nothing is registered in S0) and the
  container parameters `nowo_short_link.redirect_path_prefix`, `nowo_short_link.ui_enabled`,
  `nowo_short_link.ui_path_prefix`, `nowo_short_link.table_prefix` and `nowo_short_link.config`.
- Beacon answers in the tree: `ui.tenant_route_parameter` (route parameter of the host UI prefix, e.g. `uuid`),
  `code.reserved` (host reserved codes), `target.denylist.provider`, configurable `redirect.rate_limit.cache_pool`;
  `public_base_url` accepts `%env(DEFAULT_URI)%`.
- Quality gates: PHPUnit (100 % coverage gate), PHPStan level max with the FrankenPHP worker rulesets, PHP-CS-Fixer,
  Rector, Igor, CI matrix (PHP 8.2 to 8.5, Symfony 7.4 and 8.1), a SQLite / MySQL / MariaDB / PostgreSQL job with the
  case-sensitive code test (contract F3), a worker test (one kernel kept alive, 2,000 requests) and the demo smoke test.
- Docs set, Spec Kit baseline, Flex recipe, demos `symfony7` (Symfony 7.4) and `symfony8` (Symfony 8.1) on FrankenPHP
  worker mode with MySQL, UiKit's admin layout and default theme (REQ-DEMO-014) and FormKit (REQ-DEMO-015).

[Unreleased]: https://github.com/nowo-tech/ShortLinkBundle/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/nowo-tech/ShortLinkBundle/releases/tag/v0.1.0
