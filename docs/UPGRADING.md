# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 0.5.0](#to-050)
- [To 0.4.0](#to-040)
- [To 0.3.0](#to-030)
- [To 0.2.0](#to-020)
  - [Template contract](#template-contract)
- [To 0.1.0](#to-010)

## Unreleased

## To 0.5.0

From **0.4.0**:

```bash
composer update nowo-tech/short-link-bundle
```

```bash
composer update nowo-tech/short-link-bundle
```

The update installs `nowo-tech/transaction-contract` ^1.0 and `nowo-tech/transaction-doctrine` ^1.6 (libraries; you do
not have to register `NowoTransactionDoctrineBundle`).

**Lock conflicts answer 409 (REQ-RUNTIME-003).** A deadlock, lock wait timeout or serialization failure on the bundle's
UI or redirect routes now answers 409 with `X-Conflict-Code: CONFLICT_001` and `Retry-After` (nothing saved, retry is
safe) or `CONFLICT_002` without `Retry-After` (part of the request may be saved: reload before trying again), through
your error page, instead of a 500. To answer 503 instead:

```yaml
nowo_short_link:
    conflict_response:
        status: 503
```

When `NowoTransactionDoctrineBundle` is registered, unset options follow its `conflict_response`. Your own routes are
not affected. Cross-origin API clients that read the code need `X-Conflict-Code` in `Access-Control-Expose-Headers`.

**`TransientStorageFailure` from the PHP API.** A lock conflict inside `ShortLinkManagerInterface` now reaches your code
as `Nowo\TransactionContract\TransientStorageFailure` (the DBAL exception is its previous). Code that caught
`Doctrine\DBAL\Exception\DeadlockException` or `LockWaitTimeoutException` around the manager should catch
`TransientStorageFailure` (nothing was committed by the bundle: the call can be retried).

**Plan limits use a database lock.** Counted creates take a tenant lock on the bundle's database connection (MySQL /
MariaDB `GET_LOCK`, PostgreSQL advisory lock) in addition to symfony/lock, so the limit is exact on every server. A
create that waits longer than `quota.lock_timeout` (default 10 s) answers a lock conflict. Nothing to migrate.

**Concurrent edits.** Edits, state changes and deletes lock the row (`SELECT … FOR UPDATE`) and change only their own
fields. A disable or delete is no longer undone by an edit saved at the same time; a disable of a link deleted meanwhile
is a no-op without an event.

**Scoped DBAL middlewares.** The bundle's DBAL middlewares now wrap only the connection of `doctrine.entity_manager`
(else the default entity manager), never your other connections. Note:

- transaction-doctrine's `CommitTrackingMiddleware` is **new**. With the default `conflict_response.commit_tracking:
  bundle` it wraps the bundle's connection, which in most applications is your default connection as well.
- On **DBAL 3**, `Connection::commit()` on that connection then returns `null` instead of `true` (the middleware's
  driver connection returns nothing). Code that checks the return value of `commit()` must not rely on `true`.
- If that matters to you, set `conflict_response.commit_tracking: off` (no middleware; `CONFLICT_002` is then decided by
  the failure itself and transaction-doctrine's watermark), or register `NowoTransactionDoctrineBundle` with
  `commit_detection: true`, whose single middleware is then reused and the bundle adds none.

See [CONCURRENCY.md](CONCURRENCY.md).

## To 0.4.0

From **0.3.0**:

```bash
composer update nowo-tech/short-link-bundle
```

**New option `target.allow_own_host` (default `false`, no change unless you set it).** A host that decorated
`TargetGuardInterface` to let short links point at its own pages (for example on an intranet or `localhost`, which
OutboundUrlGuard refuses) can drop the decorator:

```yaml
nowo_short_link:
    public_base_url: '%env(DEFAULT_URI)%'
    target:
        allow_own_host: true
```

Own origins are the exact origin of `public_base_url` plus `target.own_hosts` on the default ports; the request `Host`
is never used. Short-link paths on those origins stay refused as `SelfLoop`. Set it as a literal (an env placeholder is
refused). Details in [CONFIGURATION.md](CONFIGURATION.md#own-host-targets-targetallow_own_host).

**Stricter target hosts (audit SX-I1).** A target host may now only hold letters, digits, `-`, `.` and `_` (or a
bracketed IPv6 literal without a zone id). Targets such as `https://evil%2etest/`, `https://a;b.example/` or
`http://[fe80::1%25eth0]/` are refused with `TargetRefusal::Scheme` on create / update / enable. No DNS name contains
these characters, so with the default guard (`auto`, `save_resolve_dns: true`) such targets were already refused (as
unresolvable or private). With `guard: basic`, `save_resolve_dns: false` or a host guard, a stored row with such a
host may exist: after the upgrade it can no longer be read, so its redirect answers the neutral 404 (with a warning),
the recheck skips it (with a warning), and loading it through the manager or the UI throws `TargetRefusedException`. **Before
upgrading**, look for rows of the `nowo_short_link` table (with your `doctrine.table_prefix`) whose `target_url` host
contains `%`, `~` or one of `!$&'()*+,;=`, for example with a one-off script on the DBAL connection:

```php
foreach ($connection->fetchAllAssociative('SELECT id, target_url FROM nowo_short_link WHERE deleted_at IS NULL') as $row) {
    $host = parse_url($row['target_url'], PHP_URL_HOST);
    if (is_string($host) && preg_match('/^(?:\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9._-]+)$/D', $host) !== 1) {
        echo $row['id'], ' ', $row['target_url'], PHP_EOL;
    }
}
```

Then update the target to the real host or delete the link while still on 0.3.

**Base path of `public_base_url` (audit SX-L1).** When `public_base_url` has a path (`https://host/app`), targets on
the own hosts under `/app/s/` (and `/app/s`) are now refused as `SelfLoop` like `/s/`. No action needed.

## To 0.3.0

From **0.2.0**:

```bash
composer update nowo-tech/short-link-bundle
```

**Attribute routes (REQ-SF-004), no action needed.** The routes moved to `#[Route]` attributes on
`RedirectController` and `ShortLinkUiController`. Keep both imports exactly as they are:

```yaml
nowo_short_link_redirect:
    resource: '@NowoShortLinkBundle/Resources/config/routes/redirect.php'
nowo_short_link_ui:
    resource: '@NowoShortLinkBundle/Resources/config/routes/ui.php'
    prefix: /organizations/{uuid}   # optional, unchanged
```

`redirect.php` now imports the `nowo_short_link_redirect` route loader instead of declaring the route itself; the
route (name, path, methods, requirements, `_stateless`) is the same. `redirect.path_prefix`, `ui.path_prefix` and
`ui.enabled` apply as before. Do not replace the imports with `type: attribute` on the bundle controllers (or rely on
`routing.controllers`): that skips `ui.path_prefix` and `ui.enabled`.

**Twig namespace (REQ-TWIG-001/002).** The bundle's own templates and the `not_found_template` default now render through
the `@NowoShortLinkBundle` namespace. The legacy `@NowoShortLink` namespace is still registered, so templates that
include it keep working, but a `twig.paths` entry that maps a directory onto the legacy `NowoShortLink` namespace no
longer reaches bundle renders: the bundle no longer asks for that namespace. Override templates through the bundle
namespace instead: put the file in `templates/bundles/NowoShortLinkBundle/<same path>` (for example
`templates/bundles/NowoShortLinkBundle/redirect/not_found.html.twig`); it wins for both `@NowoShortLinkBundle/...` and
`@NowoShortLink/...`. To extend the bundle's own template from an override, use `{% extends '@!NowoShortLinkBundle/ui/list.html.twig' %}`.
Move any directory you registered under `twig.paths` for the legacy namespace to `templates/bundles/NowoShortLinkBundle/`.

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

New pages `@NowoShortLinkBundle/ui/list.html.twig`, `ui/form.html.twig`, `ui/stats.html.twig` (UiKit list / form / show
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
