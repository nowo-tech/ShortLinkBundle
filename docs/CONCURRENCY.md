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
| `create()` of an uncounted kind | One unit | Unique `code` index + catch: a custom code taken at the same time answers the domain result `CodeTakenException` ("code taken" on the form), never a conflict code | 409 `CONFLICT_001` |
| `createMany()` (generated links) | One unit for the whole batch | Tenant database lock when the batch is counted or has sources, so the find-by-source idempotency ("one generated link per source") is exact; unique `code` index | 409 `CONFLICT_001` |
| `update()` (edit) | One unit | The row is read again with `SELECT … FOR UPDATE` inside the unit and only the edited fields change: a concurrent disable, delete or edit is never reverted | 409 `CONFLICT_001` |
| `disable()` / `enable()` | One unit | `SELECT … FOR UPDATE`; a link already in the state, or deleted meanwhile, is left as it is and nothing is announced | 409 `CONFLICT_001` |
| `delete()` (tombstone) | One unit | `SELECT … FOR UPDATE`; the tombstone keeps a concurrent edit; a link deleted meanwhile is announced once | 409 `CONFLICT_001` |
| Click recording (`sync`, `terminate`) | One autocommit `INSERT` (append-only) | None needed (no shared row) | None: after the response, failures are logged and never break the redirect |
| Click recording (`messenger`) | One autocommit `INSERT` | Liveness re-check by id + tenant | A lock conflict is rethrown so the transport retries (nothing was written); other failures are logged |
| `nowo:short-link:recheck` | One narrow `UPDATE … WHERE` per link (`disableIfUnchanged`: same tenant, still active, same target) | Conditional update: an edit committed meanwhile wins | Command error |
| `nowo:short-link:rollup` | One transaction per day | Idempotent rewrite of the day; runs serialised by `symfony/lock` (TTL 3600 s) when available; a day written by another run at the same time (deadlock or duplicate) is skipped and redone by the next run | Command error for other failures |
| `nowo:short-link:purge` | Keyset batches of 500, each statement its own unit | Idempotent deletes; a re-run finishes an interrupted one | Command error |
| GDPR eraser (`ShortLinkEraserInterface`) | One transaction when the link and click tables share a connection | Exact-key `UPDATE` / `DELETE` | Exception to the caller |

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
  (the unique index decides); any other unique violation is a real error.
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
  - the UI edit blocked by a row lock answers 409 + `Retry-After` + `CONFLICT_001`, nothing saved, and the retry on the
    same connection succeeds; with a write committed earlier in the request it answers `CONFLICT_002` without
    `Retry-After`.
- Unit tests cover the conflict mapping (every vendor code, the 503 policy, the runner hint, the request mark, Messenger
  stamps, unique violations and lost connections not mapped, host routes untouched), the replay rule, the per-platform
  tenant lock and the commit-tracking scoping.
- The **Dependency floor** job runs the suites against the lowest nowo-tech/transaction-* versions allowed and checks that
  the demo locks install them.

## Residual risks

- **MySQL / MariaDB inside a host transaction.** `createMany()` with sources and no counted kind may run inside an open
  host transaction; `GET_LOCK` is a session lock, released when the bundle's unit returns, before the host commits. Two
  such batches with the same source in two host transactions can both insert it. Counted creates are refused inside host
  transactions, so plan limits stay exact. PostgreSQL holds its advisory lock until the host commits.
- **Other platforms.** The tenant database lock exists on MySQL, MariaDB and PostgreSQL. On SQLite the database serialises
  writers (the loser of a race gets a lock error, 409 `CONFLICT_001`). Other platforms rely on `symfony/lock` with a
  shared store.
- **After-commit listeners.** Events are dispatched after the commit; a listener failure is logged, never thrown (the
  link exists; a rethrow would invite a retry that creates a second link). Listeners that write must be idempotent and
  handle their own retries.
- **`symfony/lock` store.** The default `flock` store serialises one host only; the database lock is what makes the plan
  limit exact across servers.
