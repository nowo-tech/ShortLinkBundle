# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.0.0](#to-100)
  - [To 1.0.0](#to-100-1)
- [To 0.8.0](#to-080)
  - [Identity-column declaration (REQ-SEC-006): drop the collation-gate exemptions](#identity-column-declaration-req-sec-006-drop-the-collation-gate-exemptions)
- [To 0.7.1](#to-071)
- [To 0.7.0](#to-070)
- [To 0.6.0](#to-060)
  - [To 0.6.0: one live link per source (UK-SL-2)](#to-060-one-live-link-per-source-uk-sl-2)
- [To 0.5.0](#to-050)
- [To 0.4.0](#to-040)
- [To 0.3.0](#to-030)
- [To 0.2.0](#to-020)
  - [Template contract](#template-contract)
- [To 0.1.0](#to-010)

## Unreleased

## To 1.0.0

From **0.8.0**:

```bash
composer update nowo-tech/short-link-bundle
```

### To 1.0.0

From **0.8.0**:

```bash
composer update nowo-tech/short-link-bundle
```

No code, configuration, schema or behaviour change from 0.8.0: nothing was deprecated in 0.x, so nothing is removed.
Coming from an older 0.x release, apply the sections below in order first (the schema steps of 0.5.0, 0.6.0 and
0.8.0, and the Twig override note of 0.3.0).

**What 1.0.0 commits to (semver: no breaking change before 2.0.0).**

- **PHP API:** the services `ShortLinkManagerInterface`, `ShortLinkStatsReaderInterface`, `ShortLinkEraserInterface`,
  `ShortLinkUsageReaderInterface` and `ShortLinkRetentionInterface` (`Nowo\ShortLinkBundle\Domain\Service`), with the
  value objects and enums of `Nowo\ShortLinkBundle\Domain\Model` they take and return.
- **Ports a host implements or replaces** (`Nowo\ShortLinkBundle\Domain\Port`): `ShortLinkAccessInterface`,
  `ShortLinkTenantContextInterface`, `ShortLinkQuotaInterface`, `TenantLockInterface`, `TargetGuardInterface`,
  `TargetDenylistInterface`, `ReservedCodeProviderInterface`, `CodeBlocklistInterface`, `CustomCodeLimiterInterface`,
  `ShortLinkIdGeneratorInterface`, `UserAgentClassifierInterface`, `ClickCountryResolverInterface`. Adding a method to
  one of them is a major change.
- **Events** (`Nowo\ShortLinkBundle\Domain\Event`): `ShortLinkCreatedEvent`, `ShortLinkUpdatedEvent`,
  `ShortLinkStateChangedEvent`, `ShortLinkDeletedEvent`, `ShortLinkTargetRefusedEvent`, `ShortLinkClickedEvent`, and
  the rule that they are dispatched after the outermost commit with ids, value objects and the target host only.
- **Exceptions:** `ShortLinkExceptionInterface` and the classes of `Nowo\ShortLinkBundle\Domain\Exception`,
  `Application\HostTransactionLostException`, and the 409 / 503 answers with `X-Conflict-Code` `CONFLICT_001` /
  `CONFLICT_002` on the bundle's routes ([CONCURRENCY.md](CONCURRENCY.md)).
- **Configuration:** the `nowo_short_link` tree of [CONFIGURATION.md](CONFIGURATION.md) (`public_base_url`,
  `redirect`, `code`, `target`, `click_log`, `tenancy`, `access`, `quota`, `kinds`, `ui`, `doctrine`,
  `conflict_response`), its defaults and its validation, and the published container parameters.
- **Routes:** the route names `nowo_short_link_redirect` and `nowo_short_link_index`, `_new`, `_edit`, `_disable`,
  `_enable`, `_delete`, `_stats`, `_stats_csv`, their paths under the configured prefixes, and the two imports
  `routes/redirect.php` and `routes/ui.php`.
- **Database:** the tables `nowo_short_link`, `nowo_short_link_click`, `nowo_short_link_click_daily` and
  `nowo_short_link_rollup_day` (with `doctrine.table_prefix`), their columns, indexes and collations. A schema change
  ships with migration SQL in this file.
- **Console:** `nowo:short-link:rollup`, `nowo:short-link:purge`, `nowo:short-link:recheck` and their options.
- **Templates:** the `@NowoShortLinkBundle` template paths, the Twig blocks documented in [TEMPLATES.md](TEMPLATES.md),
  the translation domain `NowoShortLinkBundle` and its keys, and the FormKit profile `short_link`.
- **Messenger:** the message `RecordShortLinkClick` for `click_log.write: messenger`.

**Not covered** (may change in any minor): classes and interfaces marked `@internal` (the DBAL store and readers,
`ShortLinkStoreInterface`, `ClickStoreInterface`, `ShortUrlBuilderInterface`, the Doctrine listeners, `ShortLinkRecord`,
`ShortLinkPages`, `ShortLinkExactKeyColumns` and its constants; the declared columns themselves are covered under
**Database**), the markup and CSS classes inside the templates, log messages, and the
infrastructure services' constructors.

## To 0.8.0

From **0.7.1**:

```bash
composer update nowo-tech/short-link-bundle
```

### Identity-column declaration (REQ-SEC-006): drop the collation-gate exemptions

`composer update nowo-tech/short-link-bundle` pulls one new runtime dependency (`nowo-tech/migrations-kit-contracts`,
interfaces and value objects only). No schema, configuration or behaviour change.

The bundle now declares its identity / access-key columns itself, so a host no longer excuses them by hand:

- With `nowo-tech/migrations-kit-bundle` installed, `nowo:migrations:check-key-collations` checks the eleven columns
  (`nowo_short_link.id`, `.tenant_key`, `.code`, `.source_ref`, `.created_by`, `.updated_by`,
  `nowo_short_link_click.link_id`, `.tenant_key`, `nowo_short_link_click_daily.link_id`, `.tenant_key`, `.value`,
  each with your `doctrine.table_prefix`) against the live schema. Nothing to register: the service is autoconfigured.
- **Delete the entries you wrote for these tables** in `nowo_migrations_kit.collation_check.ignore_tables` /
  `ignore_columns` (`nowo_short_link`, `nowo_short_link_click`, `nowo_short_link_click_daily`,
  `nowo_short_link_rollup_day`). The declaration already exempts the declared columns from the uniform collation gate,
  so the entries add nothing today and outlive the declaration tomorrow. An `ignore_tables` entry only affects the
  uniform gate: the key gate (MigrationsKit 2.9's `ExactKeyChecker`) reads only its own `ignore_columns` and never
  `collation_check.ignore_tables`, so the declared columns are still checked there while the entries remain; the
  entries merely keep the table's *other* columns out of the uniform gate. `nowo_short_link_rollup_day` needs no
  entry at all: it has no text column.
- What changes once they are gone: the key gate watches the eleven key columns (binary NO PAD, `ALTER TABLE` printed
  when one is not), and the uniform gate watches the *other* columns of those tables (`target_url`, `target_host`,
  `kind`, `dimension`, `ip_prefix`, …). Those carry no explicit collation: they inherit your database default, so they
  pass when that default is your `expected_collation`. If your database default is something else, convert them (or
  keep a narrow `ignore_columns` entry for them, never a whole-table one).
- A host that keeps the click tables on their own connection (`nowo_short_link.doctrine.connection`) sees those five
  columns as `skipped` with that condition: run the command against that connection to cover them.

## To 0.7.1

From **0.7.0**:

```bash
composer update nowo-tech/short-link-bundle
```

## To 0.7.0

From **0.6.0**:

```bash
composer update nowo-tech/short-link-bundle
```

From **0.6.x**: `composer update nowo-tech/short-link-bundle`. No configuration, schema or behaviour change: the
release only adds services.

- **Usage counts.** Code that counts links with its own SQL over `nowo_short_link` (for example
  `SELECT COUNT(*) … WHERE tenant_key IN (…) AND kind = ? AND deleted_at IS NULL`) can call
  `ShortLinkUsageReaderInterface::count(new ShortLinkCountQuery($tenants, $kind))` instead; `->total` is the same
  number, `->byTenant` splits it per tenant.
- **Source retention.** Code that lists old sources with its own SQL (`SELECT DISTINCT source_ref … created_at < ?`) and
  calls `eraseSource()` for each can call `ShortLinkRetentionInterface::eraseSourcesCreatedBefore(new
  SourceRetentionQuery($tenant, $kind, $before))` instead (`->sources` is the number of sources erased), or page through
  `sourcesCreatedBefore()` when it must do its own work per source first. Call it outside any open transaction.
- The table name, its columns and `eraseSource()` stay as they are, so existing SQL keeps working; the ports are the
  supported way and keep working when the table changes.

## To 0.6.0

From **0.5.0**:

```bash
composer update nowo-tech/short-link-bundle
```

### To 0.6.0: one live link per source (UK-SL-2)

From **0.5.x**: `composer update nowo-tech/short-link-bundle`, then migrate the `nowo_short_link` table (below).

**doctrine/dbal ^4 only.** 0.6.0 requires `doctrine/dbal` ^4.0. 0.5.x declared `^3.8.2 || ^4.0` but never worked on
DBAL 3 (a `ParameterType` return type in the store is a TypeError there), and on DBAL 3 without savepoints the
lost-transaction guard below could not work. Hosts on DBAL 3 must upgrade DBAL first.

**What changes.** A generated link (one with a `LinkSource`) is unique per tenant, kind, source ref and position while
it is live, on MySQL, MariaDB, PostgreSQL and SQLite. Before, on MySQL / MariaDB, two `createMany()` calls with the same
source inside two host transactions could both insert it (the `GET_LOCK` session lock ended before the host commit).
Now a unique key decides: the second call waits for the first transaction, then answers the first link. It is the
expected "only one" result, never a `CONFLICT_*` code and never a lock conflict. Plan limits are unchanged.

- `createMany()` with sources and no counted kind no longer takes the tenant database lock; it can join a host
  transaction on every platform.
- `create()` with a source that already has a live link now returns that link (no `ShortLinkCreatedEvent`) instead of
  creating a second one, like `createMany()` always did.
- The tenant database lock is taken only outside an open transaction (counted creates were already refused inside
  one), so a failed MySQL / MariaDB `RELEASE_LOCK` closes only the bundle's own connection state, never a host
  transaction.
- A batch inserts its sourced links in a fixed order (kind, ref, position), so two batches never lock crosswise.
  Deadlocks are still possible on MySQL, MariaDB and PostgreSQL (InnoDB gap locks left by a refused insert with three
  or more racers, or the host's own locks): the victim answers a lock conflict (`TransientStorageFailure`; nothing of
  the bundle's work is committed, a retry answers the existing links). Never a duplicate.
- **`createMany()` inside your own transaction (audit UY-1).** On MySQL / MariaDB a deadlock victim loses your
  **whole** transaction: InnoDB rolls it back, not only the bundle's savepoint. The bundle then throws
  `Nowo\ShortLinkBundle\Application\HostTransactionLostException` (a `TransientStorageFailure`) and leaves the
  connection inside a rollback-only transaction at your nesting level, so nothing you run afterwards can commit: your
  `commit()` fails, your `rollBack()` works as usual. On PostgreSQL only the savepoint is lost. In every case: on a
  `TransientStorageFailure` from the bundle inside your transaction, **roll back and retry your whole unit of work**;
  never catch it and carry on. A lost connection inside your transaction is rethrown as it is, with the same
  rollback-only guard. When the database cannot be reached again, no guard can be installed (there is no session to
  mark): your next statement reconnects and would autocommit, so **you must not carry on** in that case either.
  While a transaction is left rollback-only, every later write of the bundle on that connection (links, clicks)
  throws `HostTransactionLostException` instead of joining it and being lost.
- `create()` looks up the source before the plan limit: a tenant at its limit gets its existing link instead of a
  `QuotaExceededException`.
- PostgreSQL under `REPEATABLE READ` or `SERIALIZABLE` (not the default): the other transaction's link is not visible
  to yours, so the loser of the race gets `CodeTakenException` (nothing duplicated, nothing written). Retry the call
  in a new transaction.

**Migration.** New nullable column `source_slot` (the position, -1 without one, while the row is a live generated link;
NULL otherwise), new unique index `uniq_short_link_live_source (tenant_key, kind, source_ref, source_slot)`, and the old
`idx_short_link_tenant_source` is dropped (the unique index serves the same lookups). With `doctrine.table_prefix`, the
table and both index names carry the prefix.

`doctrine:migrations:diff` generates the column, the index and the drop. **Add the backfill (step 3) between the
`ADD` and the `CREATE UNIQUE INDEX`** by hand; without it, rows created before 0.6.0 are not protected.

1. Add the column.

   ```sql
   -- MySQL 8.4 / MariaDB 11.4
   ALTER TABLE nowo_short_link ADD source_slot SMALLINT DEFAULT NULL;
   -- PostgreSQL 16 / SQLite
   ALTER TABLE nowo_short_link ADD source_slot SMALLINT DEFAULT NULL;
   ```

2. Look for duplicates (live rows that share a source). The query is the same on every platform:

   ```sql
   SELECT tenant_key, kind, source_ref, COALESCE(source_position, -1) AS slot, COUNT(*) AS live_links, MIN(id) AS kept_id
   FROM nowo_short_link
   WHERE source_ref IS NOT NULL AND deleted_at IS NULL
   GROUP BY tenant_key, kind, source_ref, COALESCE(source_position, -1)
   HAVING COUNT(*) > 1;
   ```

3. Backfill: in each source group without a slotted live row, the live row with the **smallest id** (the oldest with
   the default UUIDv7 generator) gets the slot. Duplicates keep working (their codes may already be in sent e-mails)
   but hold no slot; `createMany()` answers the oldest one, as it did before. The query only touches groups with no
   slotted live row (`source_slot IS NULL` and no live row of the same key holding the slot), so it is safe to run
   again at any time (step 5) and the unique index can never fail because of it.

   ```sql
   -- MySQL 8.4 / MariaDB 11.4
   UPDATE nowo_short_link l
   JOIN (
       SELECT MIN(c.id) AS id FROM nowo_short_link c
       WHERE c.source_ref IS NOT NULL AND c.deleted_at IS NULL AND c.source_slot IS NULL
         AND NOT EXISTS (
             SELECT 1 FROM nowo_short_link s
             WHERE s.tenant_key = c.tenant_key AND s.kind = c.kind AND s.source_ref = c.source_ref
               AND s.source_slot = COALESCE(c.source_position, -1) AND s.deleted_at IS NULL
         )
       GROUP BY c.tenant_key, c.kind, c.source_ref, COALESCE(c.source_position, -1)
   ) k ON k.id = l.id
   SET l.source_slot = COALESCE(l.source_position, -1);

   -- PostgreSQL 16 / SQLite
   UPDATE nowo_short_link SET source_slot = COALESCE(source_position, -1)
   WHERE id IN (
       SELECT MIN(c.id) FROM nowo_short_link c
       WHERE c.source_ref IS NOT NULL AND c.deleted_at IS NULL AND c.source_slot IS NULL
         AND NOT EXISTS (
             SELECT 1 FROM nowo_short_link s
             WHERE s.tenant_key = c.tenant_key AND s.kind = c.kind AND s.source_ref = c.source_ref
               AND s.source_slot = COALESCE(c.source_position, -1) AND s.deleted_at IS NULL
         )
       GROUP BY c.tenant_key, c.kind, c.source_ref, COALESCE(c.source_position, -1)
   );
   ```

   Optional cleanup, only if the duplicates were never sent: delete them through `ShortLinkManagerInterface::delete()`
   (a tombstone; the codes stay taken). The ids are the live rows of step 2 with no slot:

   ```sql
   SELECT id, tenant_key, kind, source_ref, source_position, code, created_at FROM nowo_short_link
   WHERE source_ref IS NOT NULL AND deleted_at IS NULL AND source_slot IS NULL;
   ```

4. Add the unique index and drop the old one.

   ```sql
   -- MySQL 8.4 / MariaDB 11.4
   CREATE UNIQUE INDEX uniq_short_link_live_source ON nowo_short_link (tenant_key, kind, source_ref, source_slot);
   DROP INDEX idx_short_link_tenant_source ON nowo_short_link;
   -- PostgreSQL 16 / SQLite
   CREATE UNIQUE INDEX uniq_short_link_live_source ON nowo_short_link (tenant_key, kind, source_ref, source_slot);
   DROP INDEX idx_short_link_tenant_source;
   ```

5. After the deploy, once no 0.5.x node is left, clear the slot of rows deleted meanwhile, **then** run step 3 again
   (in this order: a tombstone that still holds a slot would make the backfill hit the unique index). Both statements
   are safe to repeat, on every platform:

   ```sql
   UPDATE nowo_short_link SET source_slot = NULL WHERE source_slot IS NOT NULL AND deleted_at IS NOT NULL;
   -- then step 3 again (your platform's form)
   ```

   Under live 0.6.0 traffic, step 3 may fail on the unique index (a link was created for the same source in between):
   nothing is half done, run it again.

NULLs never collide in a unique index on these platforms, so the key binds only live generated links (MySQL and MariaDB
have no partial indexes; the same plain index is used on PostgreSQL so `doctrine:schema:update` stays empty). Run
steps 1 to 4 before deploying 0.6.0: the new code writes `source_slot`. Your code must not write `source_slot` itself.

**Rolling deploy.** 0.5.x nodes may keep writing while 0.6.0 rolls out: they never read or write `source_slot`, and the
new index and the dropped one change nothing for them. Two effects until the last 0.5.x node is gone, both repaired by
step 5: links they create get no slot (no duplicate protection for those rows), and links they delete or erase keep
their slot, so 0.6.0 cannot create that source again (`CodeTakenException`) until the slot is cleared. If neither is
acceptable, stop the 0.5.x writers (or deploy at once) instead.

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
