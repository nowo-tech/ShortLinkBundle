# Short Link Bundle

[![CI](https://github.com/nowo-tech/ShortLinkBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/ShortLinkBundle/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/short-link-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/short-link-bundle) [![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/short-link-bundle.svg)](https://packagist.org/packages/nowo-tech/short-link-bundle) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net) [![Symfony](https://img.shields.io/badge/Symfony-7.4%20%7C%208.0%20%7C%208.1%2B-000000?logo=symfony)](https://symfony.com) [![GitHub stars](https://img.shields.io/github/stars/nowo-tech/ShortLinkBundle.svg?style=social&label=Star)](https://github.com/nowo-tech/ShortLinkBundle) [![Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** Install it from [Packagist](https://packagist.org/packages/nowo-tech/short-link-bundle) and give it a star on [GitHub](https://github.com/nowo-tech/ShortLinkBundle): it helps us maintain and improve the project.

**Short links for Symfony: per-tenant short codes behind a public stateless redirect (`/s/{code}`), a target guard
(OutboundUrlGuard + denylist), a privacy-preserving click log with daily rollups and retention, a PHP API for generated
links (e-mail sends) and a UiKit + FormKit management UI.**

**Standard Symfony by default, DDD / hexagonal opt-in:** zero configuration in a standard project; public ports are
interfaces and value objects with no Symfony, Doctrine or HTTP types, the bundle-owned tables are mapped in XML (with an
attribute twin) and referenced by id, and the bundle never flushes your EntityManager.

> 🚧 **Status: built slice by slice** from its design contract ([docs/CONTRACT.md](docs/CONTRACT.md), v1.0 for spec 230
> Block A): S1 (domain + persistence) and S2 (redirect + click log) are in review; statistics and the UI follow
> ([docs/ROADMAP.md](docs/ROADMAP.md)). First tag: 0.1.0, after its security audit.

> 📋 **Compatible with Symfony 7.4, 8.0 and 8.1** · PHP 8.2+ (Symfony 8.x requires PHP 8.4+)

![FrankenPHP Friendly Worker Mode](docs/images/frankenphp-friendly.png)

This bundle is **FrankenPHP worker mode friendly**.

## Features

Planned for 1.0 (contract v1.0); S0 today ships the configuration tree.

- **FrankenPHP worker mode, kernel kept alive and not reset**: readonly services, no memo of codes, links, tenants or
  IPs; a worker test (one kernel, thousands of requests over two tenants, flat memory)
  ([docs/FRANKENPHP-WORKER-AUDIT.md](docs/FRANKENPHP-WORKER-AUDIT.md)).
- **Codes**: random base62 (7 characters, case-sensitive, offensive-substring blocklist) or custom
  `[a-z0-9-]{3,32}`, unique per instance, reserved words, deleted codes quarantined for 365 days.
- **Redirect** `GET|HEAD /s/{code}`: stateless (no session, no cookie), one indexed lookup, neutral identical 404 for
  every failure, per-IP miss / hit limiters, `no-store` + `no-referrer` headers, p95 < 20 ms budget.
- **Target guard**: http(s) only, no userinfo, no private / link-local / metadata targets (DNS check at save through
  OutboundUrlGuard, literal checks on every redirect), host denylist port, self-chain refusal, daily recheck command.
- **Click log**: truncated IP (/24, /48), browser family, referrer host, optional country, machine flag for scanners and
  link previews, Global Privacy Control honoured, raw rows kept 90 days, daily rollups kept.
- **Multi-tenant**: every call takes a tenant key; host ports for tenant context, view / manage access, plan quota
  (exact with `symfony/lock`), denylist and country.
- **Generated links**: `createMany()` for e-mail sends (kind `email`, send ref + label + position, deduplicated), clicks
  per send through `bySource()`, GDPR export / erasure per send, tenant and actor.
- **UI** on UiKit + FormKit: list, create / edit, disable / enable / delete, stats with a chart and breakdowns, CSV.

## Installation

```bash
composer require nowo-tech/short-link-bundle
```

See [docs/INSTALLATION.md](docs/INSTALLATION.md). In S0 the bundle registers no service, route or table.

## Configuration

```yaml
# config/packages/nowo_short_link.yaml
nowo_short_link:
    public_base_url: '%env(DEFAULT_URI)%'
    ui:
        enabled: false
```

Every key of the contract is already accepted (strict tree): [docs/CONFIGURATION.md](docs/CONFIGURATION.md).

## Requirements

- PHP >= 8.2, < 8.6 (Symfony 8.x needs PHP 8.4+)
- Symfony 7.4, 8.0 or 8.1
- Doctrine DBAL 3.8+ / 4 and ORM 3.7+ with DoctrineBundle
- `nowo-tech/ui-kit-bundle` ^2.49, `nowo-tech/form-kit-bundle` ^2.22, `nowo-tech/outbound-url-guard-bundle` ^1.5

## Demo

`demo/symfony7` (Symfony 7.4, http://localhost:8230) and `demo/symfony8` (Symfony 8.1, http://localhost:8231):
FrankenPHP worker mode, MySQL, UiKit layout and FormKit forms, Web Profiler, Twig Inspector and Hot Reload
(`make -C demo up-symfony8`). See [docs/DEMO-FRANKENPHP.md](docs/DEMO-FRANKENPHP.md).

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [GitHub Spec Kit](docs/SPEC-KIT.md)
- [Roadmap](docs/ROADMAP.md)

### Additional documentation

- [Contract v1.0 (spec 230 Block A)](docs/CONTRACT.md)
- [Architecture](docs/ARCHITECTURE.md)
- [FrankenPHP worker audit](docs/FRANKENPHP-WORKER-AUDIT.md)
- [Performance](docs/PERFORMANCE.md)
- [Demo with FrankenPHP](docs/DEMO-FRANKENPHP.md)
- [PSR compliance](docs/PSR.md)
- [GitHub CI](docs/GITHUB_CI.md)

## Tests and coverage

```bash
make test               # PHPUnit in Docker
make test-coverage-100  # fails below 100 % of src/
make test-with-db       # Database suite on MySQL 8.4
make release-check      # every gate, demos included
```

- PHP: 100 % of `src/` (gate in CI). Database suite on SQLite, MySQL 8.4, MariaDB 10.11 and PostgreSQL 17 in CI.

## License

MIT. See [LICENSE](LICENSE).

## Author

Created by [Héctor Franco Aceituno](https://github.com/HecFranco) at [Nowo.tech](https://nowo.tech).
