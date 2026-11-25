# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.0.0] - 2026-11-23](#100---2026-11-23)
- [[0.8.0] - 2026-11-23](#080---2026-11-23)
- [[0.7.1] - 2026-11-21](#071---2026-11-21)
- [[0.7.0] - 2026-11-21](#070---2026-11-21)
- [[0.6.0] - 2026-11-19](#060---2026-11-19)
- [[0.5.0] - 2026-11-19](#050---2026-11-19)
- [[0.4.0] - 2026-11-18](#040---2026-11-18)
- [[0.3.0] - 2026-11-17](#030---2026-11-17)
- [[0.2.0] - 2026-11-12](#020---2026-11-12)
- [[0.1.0] - 2026-11-12](#010---2026-11-12)

## [Unreleased]

### Internal

- **Deptrac layer check** (REQ-ARCH-002): `deptrac.yaml` with a strict ruleset (Domain / Application framework-free,
  no skipped violations, uncovered dependencies and unassigned classes fail), `composer deptrac`, `make deptrac`, part
  of `make release-check` and of the PHPStan CI job.
- Layer fixes, behaviour unchanged in the container: `ClickRecorder` (now `@internal`) detects raw DBAL lock errors through the new
  `Domain\Port\TransientFailureDetectorInterface` (transaction-doctrine adapter wired by the container; new optional last
  constructor argument), `Domain\Model\PublicBaseUrl` holds the `public_base_url` rule (`Configuration::isBaseUrl()`
  delegates) and `Domain\TranslationDomain` the translation domain (re-exported by the bundle constant).

## [1.0.0] - 2026-11-23

Preparation of **1.0.0** (contract §13, Audit row). No code, configuration, schema or behaviour change from 0.8.0;
see [UPGRADING.md](UPGRADING.md#to-100) for what 1.0.0 commits to.

### Changed

- `ShortLinkExactKeyColumns` is marked `@internal`: hosts reach it only through MigrationsKit's
  `ExactKeyColumnsInterface` discovery, so its class constants are not part of the 1.0 API (docblock only, no
  behaviour change).

### Documentation

- Spec Kit baseline (REQ-SPECKIT-001 / 003): `code-inventory.md` maps `ShortLinkExactKeyColumns` (new `FR-DB-003`),
  162 / 162 files under `src/`, and its total row matches the audit command again (it still said 149); `spec.md`
  states the mapped count (`SC-002`) and the NO PAD collations of `FR-DB-001`.
- `docs/SECURITY.md`: controls that were still marked "Unreleased" name the release that shipped them (0.4.0, 0.5.0,
  0.8.0).
- `docs/FRANKENPHP-WORKER-AUDIT.md`: re-checked on 0.8.0; the identity-column declaration is stateless.
- `docs/UPGRADING.md`: "To 1.0.0" lists the public API that becomes a semver commitment and what stays internal.

[1.0.0]: https://github.com/nowo-tech/ShortLinkBundle/releases/tag/v1.0.0

## [0.8.0] - 2026-11-23

### Added

- **The bundle declares its own identity / access-key columns (REQ-SEC-006).** New service
  `Nowo\ShortLinkBundle\Infrastructure\Doctrine\ShortLinkExactKeyColumns`, an implementation of
  `Nowo\MigrationsKitContracts\Collation\ExactKeyColumnsInterface` (new runtime dependency
  `nowo-tech/migrations-kit-contracts`, interfaces only). It names the eleven columns compared for equality — on
  `nowo_short_link`: `id`, `tenant_key`, `code`, `source_ref`, `created_by`, `updated_by`; on `nowo_short_link_click`:
  `link_id`, `tenant_key`; on `nowo_short_link_click_daily`: `link_id`, `tenant_key`, `value` — with the configured
  `doctrine.table_prefix` applied, so a prefixed host is checked under its real table names. A host that installs
  `nowo-tech/migrations-kit-bundle` gets them in `nowo:migrations:check-key-collations` with no registration (the
  service is autoconfigured), and the same declaration exempts those columns from the uniform collation gate: the
  `collation_check.ignore_tables` / `ignore_columns` entries a host wrote for them can go (see
  [UPGRADING.md](UPGRADING.md)). When `doctrine.connection` gives the click tables their own connection, their five
  columns are declared inactive with that condition and reported as skipped instead of missing.
- The bundle's own CI now runs that gate: `ExactKeyColumnsGateTest` (database suite, MySQL 8.4 and MariaDB 11.4)
  extends MigrationsKit's `ExactKeyColumnsTestCase` and asserts every declared column of a freshly created schema is
  binary NO PAD, through a host with a table prefix. A new identity column left on the server default collation
  (`utf8mb4_0900_ai_ci`: case-, accent-insensitive and PAD SPACE) now fails this bundle's pipeline, not a host's.
  `ClickTablesSchemaListener::CLICK_BINARY_COLUMNS` / `DAILY_BINARY_COLUMNS` make the schema and the declaration one
  list, so they cannot drift apart. No schema, configuration or behaviour change.

[0.8.0]: https://github.com/nowo-tech/ShortLinkBundle/releases/tag/v0.8.0

## [0.7.1] - 2026-11-21

### Fixed

- **Numeric tenant keys in usage counts (audit YD2-L1).** `ShortLinkCounts::$byTenant` is a PHP array, so a tenant key that is a decimal integer (`"123"`) is an `int` key there, and a `strict_types` host that passed the key on to a `string` parameter (`new TenantKey($tenant)`) got a `TypeError`. New `ShortLinkCounts::perTenant()` yields every tenant asked for with its count, keyed by the exact tenant key as a string; `byTenant` and `forTenant()` are unchanged (documented in docs/USAGE.md). Counting and the deduplication of `ShortLinkCountQuery` were already exact (`"123"` and `"0123"` stay two tenants).
- **Source erasure result (audit YD2-I1).** `eraseSourcesCreatedBefore()` no longer counts, in `SourceErasureResult::$batches`, a batch whose listed sources another run erased first (it wrote nothing). `SourceErasureResult` and docs/USAGE.md now say that `complete` is also `false` in that case (the call stops rather than spin; call it again).

[0.7.1]: https://github.com/nowo-tech/ShortLinkBundle/releases/tag/v0.7.1

## [0.7.0] - 2026-11-21

### Added

- `Nowo\ShortLinkBundle\Domain\Service\ShortLinkUsageReaderInterface::count(ShortLinkCountQuery): ShortLinkCounts`: counts the links of one kind in several tenants, optionally in one state (`null` = not deleted: active, disabled and expired; `Active`, `Disabled`, `Expired`, `Deleted`), with the total and the count of every tenant asked for (zeros included). One grouped query per 500 tenants; tenant keys compare exactly. A system API (no access check) for billing and plan dashboards, so hosts no longer query the `nowo_short_link` table themselves (DDD-host checklist B7).
- `Nowo\ShortLinkBundle\Domain\Service\ShortLinkRetentionInterface` (source retention, B7):
  - `sourcesCreatedBefore(SourceRetentionQuery, ?string $after = null): SourceRefPage`: the sources of a tenant and kind with a link created before a moment, keyset-paged in ascending ref order (deleted links count, erased tombstones do not);
  - `eraseSourcesCreatedBefore(SourceRetentionQuery, ?int $maxBatches = null): SourceErasureResult`: erases those sources like `eraseSource()`, `batchSize` sources (1 to 1000, default 500) per transaction. Idempotent (an erased source no longer matches), its links are locked in id order before each batch writes (a concurrent run waits, then skips them), refused inside an open transaction, and it never touches an EntityManager. A lock conflict in the first batch is a `TransientStorageFailure` (`CONFLICT_001`, nothing committed); after a committed batch it is a `ConcurrencyConflict` with `committed` true (`CONFLICT_002`) (REQ-RUNTIME-003 rule 6).
- Value objects `ShortLinkCountQuery`, `ShortLinkCounts`, `SourceRetentionQuery`, `SourceRefPage` and `SourceErasureResult` (`Nowo\ShortLinkBundle\Domain\Model`). Both services are public aliases, available on standard and DDD hosts, with no configuration and no schema change.

### Changed

- Demo (Symfony 8 and Symfony 7 apps): runs in UiKit's demo platform shell (`nowo-tech/ui-kit-bundle` ^2.60); the demo's own masthead is gone and the management pages render inside the shell. Demo only, no bundle change.

[0.7.0]: https://github.com/nowo-tech/ShortLinkBundle/releases/tag/v0.7.0

## [0.6.0] - 2026-11-19

### Added

- `Nowo\ShortLinkBundle\Application\HostTransactionLostException` (a `TransientStorageFailure`): a bundle write that joined a host transaction was the deadlock victim and the database (MySQL / MariaDB) rolled back the whole host transaction. The connection is left in a rollback-only transaction at the host's nesting level, so the host's later statements can never autocommit; the host must roll back and retry its whole unit (audit UY-1).
- `nowo_short_link.source_slot` column and unique index `uniq_short_link_live_source (tenant_key, kind, source_ref, source_slot)`: one live generated link per tenant, kind, source ref and position on MySQL, MariaDB, PostgreSQL and SQLite (audit UK-SL-2). Migration, duplicate check and backfill per database in UPGRADING.

### Changed

- `createMany()` with sources and no counted kind no longer takes the tenant database lock: the unique key decides the race, and the call can join a host transaction on every platform. The loser of the race gets the winner's link (inside a savepoint, so the host transaction survives on PostgreSQL), never a `CONFLICT_*` code.
- `create()` with a source that already has a live link returns that link without an event, as `createMany()` does, and looks it up before the plan limit: a tenant at its limit gets its existing link (audit UY-5).
- `createMany()` inserts its sourced links in a fixed order (kind, ref, position), so two batches with the same sources never lock crosswise (audit UY-2).
- The tenant database lock (`transactional()` with a tenant) is refused inside an open transaction; only counted creates take it, and those were already refused inside host transactions. A failed MySQL / MariaDB `RELEASE_LOCK` therefore always closes the connection, never inside a host transaction; the deferred release added in 0.5.0 is removed.
- The index `idx_short_link_tenant_source` is dropped; the new unique index serves the source lookups.
- Requires `doctrine/dbal` ^4 (audit UY2-1).
- `transactional()` and the click store refuse to write into a transaction that is already rollback-only (`HostTransactionLostException`), so a host that caught the exception and carried on never loses later links or clicks silently (audit UY2-4). When reopening the lost host transaction fails after DBAL raised the level, that level is still marked rollback-only (audit UY2-3).
- `findBySource()` and the race re-read also re-check the kind in PHP (audits UY-6, UY2-6).

### Fixed

- Known issue of 0.5.0: it declared `doctrine/dbal` `^3.8.2 || ^4.0` but did not work on DBAL 3 (a `ParameterType` return type in the store is a TypeError there). 0.6.0 requires DBAL ^4 (audit UY2-2).
- MySQL / MariaDB: two `createMany()` calls with the same source inside two host transactions could both insert it, because the `GET_LOCK` session lock was released before the host committed (audit UK-SL-2, the residual risk documented in 0.5.0). PostgreSQL was not affected.

[0.6.0]: https://github.com/nowo-tech/ShortLinkBundle/releases/tag/v0.6.0

## [0.5.0] - 2026-11-19

### Added

- REQ-RUNTIME-003 rule 6: a lock conflict (deadlock, lock wait timeout, serialization failure: MySQL 1213 / 1205, MariaDB 1020, PostgreSQL 40001 / 40P01 / 55P03) on a route of the bundle answers **409** through the application's error page instead of a 500, with `X-Conflict-Code: CONFLICT_001` and `Retry-After` when nothing of the request was committed, or `CONFLICT_002` without `Retry-After` when part of it may be (a write committed earlier in the same request, a committed `ConcurrencyConflict`, a lost savepoint, a Messenger handler that already ran). New options `conflict_response.status` (409 or 503), `conflict_response.retry_after` and `conflict_response.commit_tracking` (`bundle` or `off`); unset, they follow nowo-tech/transaction-doctrine's `conflict_response`. Unique violations and lost connections are not mapped.
- `quota.lock_timeout` (default 10 s): how long a counted create waits for the tenant's database lock before answering a lock conflict. `ShortLinkQuotaInterface::limitFor()` is called while that lock is held: host implementations must not do slow I/O (HTTP, remote calls) there.
- A failed MySQL / MariaDB `RELEASE_LOCK` of the tenant lock inside an open host transaction no longer closes the connection (which would have lost the host's transaction): the release is retried after the outermost commit or rollback, and only outside a transaction is the connection closed.
- `docs/CONCURRENCY.md`: write paths, conflict codes, host options, commit tracking, tests and residual risks.
- Dependencies `nowo-tech/transaction-contract` ^1.0 and `nowo-tech/transaction-doctrine` ^1.6.

### Changed

- Write units run through transaction-doctrine's DBAL runner (`DoctrineTransactionRunner::forConnection()`): a failed unit inside a host transaction rolls back to its savepoint (also after a MySQL nested deadlock), and a lock conflict reaches the caller of `ShortLinkManagerInterface` as `Nowo\TransactionContract\TransientStorageFailure` (with the DBAL exception as previous) instead of the raw DBAL exception.
- Commit tracking and the after-commit middleware wrap only the connection of the bundle's entity manager, never the host's other connections (audit UE-CF-1); with transaction-doctrine's `commit_detection` its ledger is reused and the bundle adds no middleware.
- CI: the required Databases job runs MariaDB 11.4 and PostgreSQL 16 (was 10.11 / 17), next to MySQL 8.4 and SQLite, with the two-connection race tests; new **Dependency floor (nowo-tech/transaction-\* lowest, demo lock)** job.

### Fixed

- Plan limit under concurrent creates (0.1.0–0.4.0, security advisory, Low): without symfony/lock (or with a lock store local to one server, such as the default `flock`), concurrent counted creates could pass the plan limit by a few links. The count and the insert now run under a tenant **database** lock in the same transaction (MySQL / MariaDB `GET_LOCK`, PostgreSQL `pg_advisory_xact_lock`; SQLite serialises writers), so the limit is exact across processes and servers. `createMany()` with sources takes the same lock, so two batches never both create the generated link of one source.
- Lost updates (0.1.0–0.4.0, security advisory, Low): `update()`, `disable()` / `enable()` and `delete()` wrote back a snapshot of the row read before the transaction, so an edit racing a disable or a delete could re-enable or undelete the link, and a delete could revert a concurrent edit. The row is now read again with `SELECT … FOR UPDATE` inside the transaction and only the changed fields are written; a link already in the requested state or deleted meanwhile is left as it is, with no event.
- A write unit (0.1.0–0.4.0, security advisory, Low) whose connection was lost during `COMMIT` (outcome unknown) was replayed once on a new connection, which could create a second link; it is now rethrown. A connection lost before the work finished is still replayed once (nothing was committed).
- Messenger click writes (0.1.0–0.4.0, security advisory, Low; `click_log.write: messenger`): a lock conflict of the insert or of the liveness check (also a raw DBAL lock error of the liveness read) is rethrown so the transport retries the message, instead of the click being dropped with a warning (rule 8). The `sync` and `terminate` modes keep logging every failure after the response.

[0.5.0]: https://github.com/nowo-tech/ShortLinkBundle/releases/tag/v0.5.0

## [0.4.0] - 2026-11-18

### Added

- `target.allow_own_host` (default `false`; SYMFONY-BEACON re-audit 2026-11-17 ask C4, for KIT-81 e-mail click links): targets on the application's own origins are allowed without OutboundUrlGuard and the denylist, so an intranet or `localhost` application can link to its own pages without decorating `TargetGuardInterface`. Own origins come from configuration only: the exact `scheme://host:port` of `public_base_url` and each exact `target.own_hosts` host on ports 80 / 443; the request `Host` is never read (same verdict over HTTP, on the CLI, in Messenger and in a kept-alive worker). On an own origin, `self_paths` and the redirect prefix (the prefix itself included) stay refused as `SelfLoop`. The new `OwnHostGuard` wraps the `auto` / `basic` chain or the `target.guard` service. An env placeholder for the option is refused (compile time).

### Fixed (hardening)

- Audit SX-L1 (present up to 0.3.0): with a `public_base_url` that has a path (`https://beacon.internal:8443/app`, short URLs at `/app/s/…`), the self-chain check only compared paths from the host root, so `https://…/app/s/abc` was not refused as `SelfLoop`. `SelfLoopGuard` and `OwnHostGuard` now also refuse every self path under the base path (`/app/s/`, `/app/s`), next to the root form; the base path is read at runtime (env placeholders), with the same decoding and case handling as before.
- Audit SX-I1 (present up to 0.3.0): `TargetUrl` hosts now keep only letters, digits, `-`, `.` and `_` (or a bracketed IPv6 literal of hex digits, `:` and `.`); `%`, `~` and the sub-delims `!$&'()*+,;=` are refused as `Scheme`. Browsers percent-decode a host (`evil%2etest` → `evil.test`, `127%2e0%2e0%2e1` → `127.0.0.1`); the default guards refused those only incidentally (`PrivateHost`), a host guard trusting `TargetUrl::host()` would not have. IPv6 zone ids (`[fe80::1%25eth0]`) are refused too. Stored rows with such a host can no longer be read: check them before upgrading ([UPGRADING](UPGRADING.md#to-040)).

### Tests

- `OwnHostGuardTest`: own origins (case, trailing dot, default ports), redirect-path loops (`/s`, `/S/`, `%73`, `%2F`, `//`, `..`), foreign look-alikes (port, scheme, subdomain, suffix, IDN / punycode homograph, `%2e` / `%40` in the host), userinfo and scheme-relative forms; `WorkerRequestLoopTest`: a kept-alive kernel with spoofed private `Host` headers gives the same verdicts as no request.

### Documentation

- UPGRADING (0.3.0): `twig.paths` overrides of the legacy `@NowoShortLink` namespace no longer reach bundle renders; override through `templates/bundles/NowoShortLinkBundle/` (audit SD follow-up). No code change.

[0.4.0]: https://github.com/nowo-tech/ShortLinkBundle/releases/tag/v0.4.0

## [0.3.0] - 2026-11-17

### Changed

- Twig namespace: the bundle's own templates, the `redirect.not_found_template` default and the docs use `@NowoShortLinkBundle/...` (REQ-TWIG-002); a new `TwigPathsPass` registers the `NowoShortLinkBundle` namespace (and the `templates/bundles/NowoShortLinkBundle` override directory) while Symfony keeps the legacy `@NowoShortLink` namespace, so a host config value or template reference with the old namespace keeps working.
- `RecordShortLinkClickHandler` carries `#[AsMessageHandler]` and is registered autoconfigured instead of with a hand-written `messenger.message_handler` tag (REQ-MSG-001).
- Routes are declared with `#[Route]` attributes (REQ-SF-004; owner approval 2026-11-17): the redirect route on `Infrastructure\Http\RedirectController`, the UI routes on `Presentation\Controller\ShortLinkUiController`. `Resources/config/routes/redirect.php` now imports the new `nowo_short_link_redirect` loader (`Infrastructure\Routing\RedirectRouteLoader`), and the `nowo_short_link_ui` loader reads the UI attributes and still adds `ui.path_prefix` and the `ui.enabled` switch; both use an internal `ControllerAttributeRouteLoader`, so a host decorating `routing.loader.attribute` cannot change the bundle routes. Every route keeps its name, path, methods, requirements, defaults, options and order (fixture test against the 0.2 collection on the standard and DDD hosts, default and custom prefixes; demo `debug:router` dumps in `tests/Fixtures/Routing/`). The controller classes are now router cache resources. No host change: keep importing `redirect.php` and `ui.php`. See [UPGRADING](UPGRADING.md#to-030).

### Tests

- `HostRoutingControllersTest` (REQ-SF-004 rule 7): a kernel whose routing is only `routing.controllers` gets no bundle route, even with the UI enabled.

### Documentation

- Messenger click writes: transport, retries, failure transport, redelivery and logging documented in CONFIGURATION (REQ-MSG-001).

- Spec Kit baseline (REQ-SPECKIT-003): `specs/001-baseline` maps the S4 management UI (controller, route loader, views, view models, CSV, FormKit Types), the custom-code limiter, the quota display and `BinaryCollation` (145/145 units; US-08, FR-CODE-003, FR-QUOTA-002, FR-UI-001/002).

[0.3.0]: https://github.com/nowo-tech/ShortLinkBundle/releases/tag/v0.3.0

## [0.2.0] - 2026-11-12

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
- Audits L7 / NY-S4 L2–L3: per-person limit on custom-code lookups (`code.custom_code_rate_limit`, default 10 per
  minute per tenant + actor, stored in `redirect.rate_limit.cache_pool`, atomic across processes with symfony/lock;
  port `CustomCodeLimiterInterface`, replaceable through `code.custom_code_rate_limit.limiter`; new
  `CodeAttemptsExceededException`). The attempt is consumed before the lookup (concurrent requests cannot all pass a
  peek), so free codes count too; callers without an actor share one window per tenant unless the host's limiter keys
  them better. Over the limit no custom code is looked up until the window ends; `system` callers and random codes
  are never limited.
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

- Audit NY-S4 L1: links of kinds hidden from people (`show_in_ui: false`, e.g. `email`) answer 404 on every UI page
  and action by id (edit, statistics, CSV, disable, enable, delete); they could be opened and changed by id before.
- Audit NY-S4 L4: `quota.default_limit: null` (or `~`) is accepted again and means unlimited.
- Audit NY-S4 N1: env placeholders. Every key with a custom validator was checked in a real compiled container
  (MergeExtensionConfigurationPass + ValidateEnvPlaceholdersPass): `public_base_url`, `quota.default_limit`
  (`%env(int:…)%`), the durations (`code.quarantine`, `click_log.retention`, `click_log.rollup.retention`), the
  rate-limit intervals, `ui.tenant_route_parameter`, `target.self_paths`, the host lists and the integer keys accept
  one (the retention cross-check is left to runtime when a value is a placeholder); `redirect.path_prefix`,
  `ui.path_prefix` and `doctrine.table_prefix` refuse one with a clear message (they are used while compiling); a
  literal empty value is still refused.
- `ActorRef` accepts ZWNJ / ZWJ (U+200C / U+200D) inside a value (Persian names, emoji sequences), never at its
  edges.
- PAD SPACE sweep (affects 0.1.0; no advisory: 0.x release, host-triggered eraser): `utf8mb4_bin` is PAD SPACE on
  MySQL / MariaDB, so `anonymiseActor('u1 ')` also cleared the attribution of `u1`. `ActorRef` now refuses leading /
  trailing whitespace, control and format characters and invalid UTF-8 (`ActorRef::fromStored()` keeps reading 0.1.0
  rows); `anonymiseActor()` selects the candidates, re-checks them with `===` in PHP and updates by primary key; the
  identifier columns (`id`, `tenant_key`, `code`, `source_ref`, `created_by`, `updated_by`, click `link_id` /
  `tenant_key`) use a NO PAD binary collation: `utf8mb4_0900_bin` on MySQL 8 (8.0.17+), `utf8mb4_nopad_bin` on
  MariaDB, `utf8mb4_bin` on older MySQL. The role-based access port records no actor for an identifier the rule
  refuses.
- The list search (code, title, target host) is case-insensitive on every database (it was case-sensitive on
  PostgreSQL, and for codes on MySQL / MariaDB). SQLite's `LOWER()` folds ASCII letters only.

### Security

- CSV cells starting with `=`, `+`, `-`, `@`, a tab or a carriage return (also after leading spaces) are prefixed with `'`; target URLs are printed
  escaped and linked only when http(s), with `rel="noopener noreferrer nofollow"`. The forms never map `system` or
  `kind` (extra fields are refused); people only create `manual` links.

[0.2.0]: https://github.com/nowo-tech/ShortLinkBundle/releases/tag/v0.2.0

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

[Unreleased]: https://github.com/nowo-tech/ShortLinkBundle/compare/v1.0.0...HEAD
[0.1.0]: https://github.com/nowo-tech/ShortLinkBundle/releases/tag/v0.1.0
