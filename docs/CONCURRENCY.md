# Concurrency

How the bundle stays correct when the same links are written at the same time: two users, two workers, a retried
request or a replayed Messenger message (nowo-tech REQ-RUNTIME-003). The bundle writes
through Doctrine DBAL on the connection of its entity manager (`doctrine.entity_manager`, else the default one), never
through the ORM unit of work, so the host's EntityManager is never flushed, cleared or reset.

## Table of contents

- [Write paths](#write-paths)
- [Conflict answers](#conflict-answers)
- [Host options](#host-options)
- [Commit tracking](#commit-tracking)
- [Tests](#tests)
- [Residual risks](#residual-risks)

## Write paths

Every write unit runs through `DbalShortLinkStore::transactional()`, which uses nowo-tech/transaction-doctrine's DBAL
runner (`DoctrineTransactionRunner::forConnection()`): one transaction per unit, joined to an open host transaction
through a savepoint; a failed unit is rolled back to the level it started at (savepoint-aware, rule 3) and a lock
conflict is rethrown as a `TransientStorageFailure`. Events go out after the outermost commit, never on rollback.

| Path | Transaction | Concurrency control | Conflict answer |
|------|-------------|---------------------|-----------------|
| `create()` of a counted kind (UI form, API) | One unit; refused inside an open host transaction (the tenant lock must cover the commit) | Tenant **database lock** taken first in the unit (MySQL / MariaDB `GET_LOCK`, PostgreSQL `pg_advisory_xact_lock`; SQLite serialises writers), then count + insert; plus `symfony/lock` per tenant when the host has a lock factory | Lock not obtained within `quota.lock_timeout`, deadlock, lock wait timeout: 409 `CONFLICT_001` |
| `create()` of an uncounted kind | One unit | Unique `code` index + catch: a custom code taken at the same time answers the domain result `CodeTakenException` ("code taken" on the form), never a conflict code. With a source: the live-source unique key, as `createMany()` | 409 `CONFLICT_001` |
| `createMany()` (generated links) | One unit for the whole batch; may join a host transaction unless counted (a deadlock victim that lost the whole host transaction: `HostTransactionLostException`, connection left rollback-only) | Tenant database lock only when the batch is counted (plan limit). Sourced links are inserted in a fixed order (kind, ref, position). "One live link per source" is the unique key `uniq_short_link_live_source (tenant_key, kind, source_ref, source_slot)` on every platform: each sourced insert runs in a savepoint inside a transaction; a duplicate waits for the other transaction, and on its commit the unique violation rolls back to the savepoint and the batch answers the other link (read with a shared lock on MySQL / MariaDB). Never a conflict code. Unique `code` index | 409 `CONFLICT_001` (lock conflicts only) |
| `update()` (edit) | One unit | The row is read again with `SELECT … FOR UPDATE` inside the unit and only the edited fields change: a concurrent disable, delete or edit is never reverted | 409 `CONFLICT_001` |
| `disable()` / `enable()` | One unit | `SELECT … FOR UPDATE`; a link already in the state, or deleted meanwhile, is left as it is and nothing is announced | 409 `CONFLICT_001` |
| `delete()` (tombstone) | One unit | `SELECT … FOR UPDATE`; the tombstone keeps a concurrent edit; a link deleted meanwhile is announced once | 409 `CONFLICT_001` |
| Click recording (`sync`, `terminate`) | One autocommit `INSERT` (append-only) | None needed (no shared row) | None: after the response, failures are logged and never break the redirect |
| Click recording (`messenger`) | One autocommit `INSERT` | Liveness re-check by id + tenant | A lock conflict is rethrown so the transport retries (nothing was written); other failures are logged |
| `nowo:short-link:recheck` | One narrow `UPDATE … WHERE` per link (`disableIfUnchanged`: same tenant, still active, same target) | Conditional update: an edit committed meanwhile wins | Command error |
| `nowo:short-link:rollup` | One transaction per day | Idempotent rewrite of the day; runs serialised by `symfony/lock` (TTL 3600 s) when available; a day written by another run at the same time (deadlock or duplicate) is skipped and redone by the next run | Command error for other failures |
| `nowo:short-link:purge` | Keyset batches of 500, each statement its own unit | Idempotent deletes; a re-run finishes an interrupted one | Command error |
| GDPR eraser (`ShortLinkEraserInterface`) | One transaction when the link and click tables share a connection | Exact-key `UPDATE` / `DELETE` | Exception to the caller |
| `ShortLinkRetentionInterface::eraseSourcesCreatedBefore()` | One unit per batch through the DBAL runner; refused inside an open transaction (each batch commits on its own) | The batch's links are read again with `SELECT … FOR UPDATE` in id order (SQLite serialises writers): a concurrent run waits, then finds them erased (the source ref is blanked) and skips them. Idempotent: a re-run erases only what is left | `TransientStorageFailure` to the caller: `CONFLICT_001` in the first batch (nothing committed); a `ConcurrencyConflict` with `committed` true after a committed batch, or once click rows on a separate `doctrine.connection` were deleted (`CONFLICT_002`) |
| `ShortLinkUsageReaderInterface`, `sourcesCreatedBefore()` | None (reads) | — | — |

The redirect itself only reads (one indexed query); it answers 404 / 302 / 429 and never a conflict.

## Conflict answers

A lock conflict, deadlock, lock wait timeout or serialization failure (MySQL 1213 / 1205, MariaDB 1020, PostgreSQL
40001 / 40P01 / 55P03) on a route of the bundle (the UI, the redirect) answers through the application's error page:

| Code | When | Status | Headers |
|------|------|--------|---------|
| `CONFLICT_001` | Nothing of the request was committed: retrying is safe | 409 (or `conflict_response.status: 503`) | `X-Conflict-Code: CONFLICT_001`, `Retry-After` |
| `CONFLICT_002` | Part of the request may already be committed (a write earlier in the same request, a committed `ConcurrencyConflict`, a lost savepoint, or a Messenger handler that already ran): read again, do not repeat | 409 (or 503) | `X-Conflict-Code: CONFLICT_002`, no `Retry-After` |

- The message is fixed and client-safe; the storage error stays as the previous exception for the logs.
- Unique violations are not conflicts. A custom code taken at the same time is the domain answer "code taken" on the form
  (the unique index decides); a generated link whose source another transaction committed meanwhile answers that link
  (the "only one" race, audit UK-SL-2); any other unique violation is a real error.
- A lost connection is not a conflict either. A write unit whose connection was lost **before its work finished** is
  replayed once on a new connection (the server dropped the uncommitted transaction); lost **during COMMIT** the outcome
  is unknown and the error is rethrown, never replayed (a replay could create a second link).
- The host's routes keep the host's error handling, and the PHP API (`ShortLinkManagerInterface`) throws the
  `TransientStorageFailure` to its caller, which decides (a Messenger handler that calls the manager can be retried:
  nothing was committed).

## Host options

```yaml
nowo_short_link:
    quota:
        lock_timeout: 10            # seconds a counted create waits for the tenant's database lock (1..300)
    conflict_response:
        status: null                # 409 or 503; null = transaction-doctrine's conflict_response, else 409
        retry_after: null           # seconds of a CONFLICT_001 answer; null = transaction-doctrine's, else 1
        commit_tracking: bundle     # bundle | off (see below)
```

## Commit tracking

`CONFLICT_002` needs to know whether anything was committed earlier in the request. The conflict listener takes a mark
at `kernel.request` (main request) and compares at the failure:

- with nowo-tech/transaction-doctrine's bundle registered and `commit_detection: true`, its `CommitLedger` and request
  watermark are reused (`ConflictCode::forRequest()`); the bundle adds no DBAL middleware;
- otherwise (`commit_tracking: bundle`, the default) transaction-doctrine's `CommitTrackingMiddleware` is registered on
  **the bundle's connection only**, never on the host's other connections (audit UE-CF-1);
- `commit_tracking: off` registers nothing: only the failure itself and transaction-doctrine's watermark decide.

The bundle's after-commit middleware (events of writes that joined a host transaction) is scoped to the same
connection.

## Tests

- `tests/Integration/Database` runs on SQLite in every job and on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 in the
  required **Databases** job, with session lock timeouts so a hang fails fast:
  - the tenant lock held on one connection makes a counted create on another wait and give up (`quota.lock_timeout`),
    nothing written; then both connections together create exactly the plan limit;
  - an edit waits for the row lock of another connection and fails instead of overwriting the row;
  - ten processes create at once against a limit of three: exactly three succeed (standard host with symfony/lock and
    the database lock, DDD host with the database lock only);
  - another process inserts a source inside its host transaction and holds it open; `createMany()` with the same source
    inside a host transaction on this connection waits, then answers the other link: one row, no conflict, the host
    transaction still commits; six processes in host transactions create the same three sources at once: three rows,
    every process that commits answers the same links, an InnoDB deadlock victim answers a lock conflict (audit
    UK-SL-2); a real deadlock whose victim is `createMany()` inside a host transaction: on MySQL / MariaDB
    `HostTransactionLostException`, the edit made before and the write made after are never committed; on
    PostgreSQL the host transaction survives and commits (audit UY-1);
  - the UI edit blocked by a row lock answers 409 + `Retry-After` + `CONFLICT_001`, nothing saved, and the retry on the
    same connection succeeds; with a write committed earlier in the request it answers `CONFLICT_002` without
    `Retry-After`.
  - the batched source erasure: another connection holds a link of the first batch, the erasure waits (1 s session
    lock wait) and gives up with a transient failure whose code is `CONFLICT_001`, nothing erased; with a committed
    batch before the conflict it is a committed `ConcurrencyConflict` (`CONFLICT_002`), and the retry erases only
    what is left (standard and DDD host).
- Unit tests cover the conflict mapping (every vendor code, the 503 policy, the runner hint, the request mark, Messenger
  stamps, unique violations and lost connections not mapped, host routes untouched), the replay rule, the per-platform
  tenant lock and the commit-tracking scoping.
- The **Dependency floor** job runs the suites against the lowest nowo-tech/transaction-* versions allowed and checks that
  the demo locks install them.

## Residual risks

- **Release of the MySQL / MariaDB lock.** The tenant lock is taken only outside an open transaction (counted creates;
  `transactional()` refuses it inside one). A failed `RELEASE_LOCK` closes the connection, so the session lock goes
  with it; no host transaction can be open at that point.
- **PostgreSQL under `REPEATABLE READ` / `SERIALIZABLE`.** The loser of a source race cannot see the winner's link in
  its snapshot and gets `CodeTakenException`; nothing is duplicated or written. The default `READ COMMITTED` answers the
  winner's link. MySQL / MariaDB read it with a locking read, so `REPEATABLE READ` answers it too.
- **Deadlocks on every platform.** A batch inserts its sourced links in a fixed order (kind, ref, position), so two
  batches never wait for each other crosswise (audit UY-2). Deadlocks are still possible on MySQL, MariaDB and
  PostgreSQL: a refused insert leaves InnoDB gap locks behind (inherited from the rolled-back row) until its
  transaction ends, so three or more racers can deadlock, and so can the host's own locks combined with the bundle's.
  The victim answers a lock conflict (409 `CONFLICT_001` on a route, a `TransientStorageFailure` from the PHP API;
  nothing of the bundle's work is committed and a retry answers the existing links). It is never a duplicate. Two
  racers (the usual case: a retried send) get the existing link. Keep host transactions around `createMany()` short:
  until they end, other inserts into the table may wait for those gap locks.
- **A deadlock victim inside a host transaction (audit UY-1).** On MySQL / MariaDB InnoDB rolls back the **whole**
  host transaction, not only the bundle's savepoint; `ROLLBACK TO SAVEPOINT` then fails (1305) and the connection is
  reopened. The bundle never leaves DBAL at a lower nesting level on that fresh session (the host's next statements
  would autocommit: a partial host commit): it opens the host's levels again, marks them rollback-only and throws
  `HostTransactionLostException` (a `TransientStorageFailure`). Whatever the host runs afterwards is never committed;
  its `commit()` fails and its `rollBack()` balances. The host must roll back and retry its whole unit of work, never
  continue it. On PostgreSQL a deadlock aborts only the savepoint and the host transaction survives. When the database
  cannot be reached again, no guard can be installed (there is no session to mark rollback-only): the host's next
  statement reconnects and would autocommit, so the host must not carry on in that case either.
- **A host that catches `HostTransactionLostException` and continues without rolling back** leaves the worker's
  connection stuck in the rollback-only transaction. transaction-doctrine's repair does not cover this in general: it
  runs only at `kernel.terminate`, after a request or message that **failed with a database exception**, and only with
  its `worker_hygiene` on; a request that caught the exception and answered normally is not repaired. Later writes on
  that connection would join the doomed transaction and be lost; the bundle's own writes refuse to: `transactional()`
  (links) and the click store throw `HostTransactionLostException` while the transaction is rollback-only, so the
  failure stays visible until the host rolls back.
- **Rows created before 0.6.0, and by 0.5.x nodes during a rolling deploy.** The UPGRADING backfill gives the slot to
  the live link with the smallest id (the oldest with the default UUIDv7 generator) of each source; other duplicates
  stay live without one (their codes may be in sent e-mails) and `createMany()` answers the oldest. The backfill is
  re-runnable and is run again after the deploy, with a statement that clears the slot of rows 0.5.x nodes deleted.
- **Other platforms.** The tenant database lock exists on MySQL, MariaDB and PostgreSQL. On SQLite the database serialises
  writers (the loser of a race gets a lock error, 409 `CONFLICT_001`). Other platforms rely on `symfony/lock` with a
  shared store.
- **After-commit listeners.** Events are dispatched after the commit; a listener failure is logged, never thrown (the
  link exists; a rethrow would invite a retry that creates a second link). Listeners that write must be idempotent and
  handle their own retries.
- **Quota port under the lock.** `ShortLinkQuotaInterface::limitFor()` runs while the tenant's database lock is held.
  A host implementation must answer fast (memory, cache, one local query), never with HTTP or other slow I/O: every
  counted create of the tenant waits for it and gives up after `quota.lock_timeout`.
- **`symfony/lock` store.** The default `flock` store serialises one host only; the database lock is what makes the plan
  limit exact across servers.
