# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- S2 public redirect (contract §7): route `nowo_short_link_redirect` `GET|HEAD {redirect.path_prefix}/{code}` (import
  `@NowoShortLinkBundle/Resources/config/routes/redirect.php`), stateless, step order of §7.1, protective headers on every
  answer, one neutral 404 (byte-identical for unknown, disabled, expired, deleted and refused links) and a 429 variant
  with `Retry-After`, per-IP miss / hit limiters on a configurable cache pool (`redirect_unlogged` or `block` over the hit
  limit), redirect guard without DNS.
- Click log: truncated IP (/24, /48), browser family (built-in classifier: browsers, bots, link previews, e-mail
  scanners), referrer host, optional country (`click_log.country_resolver`, local lookups only), machine flag, `Sec-GPC`
  honoured (count only), written after the response (`terminate`), through Messenger (`RecordShortLinkClick`, never the
  raw IP) or synchronously; failures never break the redirect; `ShortLinkClickedEvent`.

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
