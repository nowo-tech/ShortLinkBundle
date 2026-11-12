# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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
