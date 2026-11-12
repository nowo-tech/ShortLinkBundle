# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 0.2.0](#to-020)
  - [Template contract](#template-contract)
- [To 0.1.0](#to-010)

## Unreleased

## To 0.2.0

From **0.1.0**:

```bash
composer update nowo-tech/short-link-bundle
```

S4 (0.2.0) adds the management UI; nothing changes until you enable it.

1. Register `NowoUiKitBundle` and `NowoFormKitBundle` (already Composer requirements) when you turn the UI on; the
   container refuses `ui.enabled: true` without them, without `framework.form`, the translator or Twig.
2. Set `nowo_short_link.ui.enabled: true` and import the routes under your prefix:

   ```yaml
   nowo_short_link_ui:
       resource: '@NowoShortLinkBundle/Resources/config/routes/ui.php'
       prefix: /organizations/{uuid}        # optional host prefix
   ```

   With a prefix parameter, set `ui.tenant_route_parameter` (Beacon: `uuid`) so the UI keeps it in its links.
3. The pages need sessions (CSRF tokens) and your firewall in front of them; access is the access port (`View` /
   `Manage`), the tenant the tenant-context port.
4. New configuration keys with defaults: `code.custom_code_rate_limit` (`enabled: true`, `limit: 10`,
   `interval: 1 minute`, `limiter: null`) and `ui.layout_template` (null). The limiter applies to every custom-code
   lookup of `create()` except `system` commands (per tenant + actor); catch `CodeAttemptsExceededException` if you
   call it from your own forms. Callers your access port does not identify share one window per tenant: give your own
   `CustomCodeLimiterInterface` (`limiter`) to key them by session or network.
5. MySQL / MariaDB: the identifier columns move to a NO PAD binary collation (`utf8mb4_0900_bin` on MySQL 8.0.17+,
   `utf8mb4_nopad_bin` on MariaDB). Run `doctrine:migrations:diff` and apply the `ALTER TABLE … MODIFY` it generates
   for `nowo_short_link` and the click tables. MySQL 8.0.0–8.0.16 (no `utf8mb4_0900_bin`) is not supported.
   Changing a column collation rebuilds the table (a full copy, writes blocked while it runs). The click tables can be
   large: run the migration in a maintenance window, or with an online schema change tool (pt-online-schema-change,
   gh-ost) on busy hosts; purge old raw clicks first (`nowo:short-link:purge`) to shorten it.
6. `ActorRef::fromString()` refuses leading / trailing whitespace and control or format characters. A host access
   port that builds actors from such identifiers must normalise them; stored 0.1.0 rows still read back
   (`ActorRef::fromStored()`).
7. The recheck may now abort earlier in a run (per-link threshold); scripts that parse its output keep the same
   messages.

### Template contract

New pages `@NowoShortLink/ui/list.html.twig`, `ui/form.html.twig`, `ui/stats.html.twig` (UiKit list / form / show
scaffolds) with the data blocks listed in [TEMPLATES.md](TEMPLATES.md).

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
