# Installation

## Table of contents

- [Requirements](#requirements)
- [Install](#install)
- [Twig Extra Bundle](#twig-extra-bundle)
- [Database](#database)
- [Next steps](#next-steps)

## Requirements

- PHP 8.2 to 8.5 (Symfony 8.x needs PHP 8.4+).
- Symfony 7.4, 8.0 or 8.1 (CI covers 7.4 and 8.1).
- DoctrineBundle with the ORM (`doctrine/orm` ^3.7, `doctrine/dbal` ^3.8.2 or ^4).
- `nowo-tech/ui-kit-bundle` ^2.49, `nowo-tech/form-kit-bundle` ^2.22 and `nowo-tech/outbound-url-guard-bundle` ^1.5
  (installed with the package; the UI stays off until `ui.enabled: true`).
- Optional: `symfony/messenger` (click write mode `messenger`), `symfony/lock` (exact plan limits under concurrency).
- The package is built slice by slice (S0 today, no tag yet): see [ROADMAP.md](ROADMAP.md) before you depend on it.

## Install

```bash
composer require nowo-tech/short-link-bundle
```

With Symfony Flex the recipe registers the bundle and copies `config/packages/nowo_short_link.yaml`. Without Flex,
register it yourself (UiKit, FormKit and OutboundUrlGuard too, if they are not registered yet):

```php
// config/bundles.php
return [
    // ...
    Nowo\ShortLinkBundle\NowoShortLinkBundle::class => ['all' => true],
];
```

## Twig Extra Bundle

The package ships Twig templates (REQ-TWIG-004): install `twig/extra-bundle` (required by the package) and register
`Twig\Extra\TwigExtraBundle\TwigExtraBundle` in `config/bundles.php` (Flex does it).

## Database

S0 creates no table. From S1 the bundle adds `nowo_short_link` (ORM entity) and the DBAL tables
`nowo_short_link_click`, `nowo_short_link_click_daily` and `nowo_short_link_rollup_day` (all with
`doctrine.table_prefix`); generate the migration on the host with `doctrine:migrations:diff`. The reference DDL is in
[CONTRACT.md](CONTRACT.md) §5 and will be copied to [UPGRADING.md](UPGRADING.md) with the release that adds it.

## Next steps

- [Configuration](CONFIGURATION.md)
- [Usage](USAGE.md)
- [Security](SECURITY.md)
