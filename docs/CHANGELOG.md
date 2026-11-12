# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Audit NW follow-ups on S1: the self-chain guard decodes percent-encoded unreserved characters and removes dot segments
  before comparing, counts the `public_base_url` host as own and always includes `redirect.path_prefix` + `/`; every
  anchored pattern uses the `D` modifier (a trailing newline never passes); counted creates (plan limit) inside an open
  database transaction are refused, so the tenant lock always covers the commit; `list()` only returns kinds shown in the
  UI unless the query is `system`; targets refuse invisible / bidirectional format characters and percent-encode
  non-ASCII bytes (the stored URL and `Location` are pure ASCII).

### Added

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
