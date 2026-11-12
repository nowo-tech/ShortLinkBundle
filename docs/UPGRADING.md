# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 0.1.0](#to-010)

## Unreleased

Nothing yet.

## To 0.1.0

First release; install with:

```bash
composer require nowo-tech/short-link-bundle
```

S1 adds four tables (names with `doctrine.table_prefix`): `nowo_short_link` (ORM-mapped, XML by default) and the DBAL
tables `nowo_short_link_click`, `nowo_short_link_click_daily` and `nowo_short_link_rollup_day` (added to the schema of
the bundle's entity manager by a `postGenerateSchema` listener). Run `doctrine:migrations:diff` on the host; the bundle
ships no migrations. Reference DDL: [CONTRACT.md](CONTRACT.md) §5. On MySQL / MariaDB the code and identifier columns
use `utf8mb4_bin`. With `doctrine.connection` set, the click tables are not added to the ORM schema: create them on
that connection yourself (same DDL).
