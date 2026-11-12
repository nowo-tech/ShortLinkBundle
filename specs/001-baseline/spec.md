# Feature Specification: ShortLinkBundle baseline

**Feature Branch**: `001-baseline`
**Created**: 2026-11-12
**Status**: Baseline (S0 scaffold, S1 domain + persistence, S2 redirect)
**Input**: Contract v1.0 for spec 230 Block A ([`docs/CONTRACT.md`](../../docs/CONTRACT.md)), owner and Beacon answers
of 2026-11-12 (§17).

Related: [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md),
[`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md), [`docs/ROADMAP.md`](../../docs/ROADMAP.md),
[`code-inventory.md`](code-inventory.md).

## Summary

S0 guarantees: the bundle registers in a standard Symfony host with zero configuration; the configuration root
`nowo_short_link` is strict (unknown keys and invalid values are refused with the path in the message) and already holds
every key of the contract plus the Beacon additions (`ui.tenant_route_parameter`, `target.denylist.provider`); S0
registers no service, route, listener or table, whatever the configuration says; the container parameters
`nowo_short_link.redirect_path_prefix`, `nowo_short_link.ui_enabled`, `nowo_short_link.ui_path_prefix`,
`nowo_short_link.table_prefix` and `nowo_short_link.config` exist.

## User scenarios

### US-01 — Install and configure (S0)

- **Given** a standard Symfony host, **when** the bundle is registered without configuration, **then** the container
  builds, the defaults of contract §4 apply and no bundle service exists.
- **Given** `public_base_url: '%env(DEFAULT_URI)%'`, **when** the container builds, **then** the placeholder is accepted.
- **Given** `ui.path_prefix` equal to `redirect.path_prefix`, a 301 status, a `/` prefix, a counted kind that is not
  configured or an unknown key, **when** the container builds, **then** it fails and names the path.

### US-02 — Case-sensitive codes on every database (S0 probe, S1 table)

- **Given** a `code` column declared like the S1 table (binary collation on MySQL / MariaDB), **when** `aBcdEf1` and
  `ABCDEF1` are inserted, **then** both are accepted under the unique index and each lookup answers its own row only.

### US-03 — Worker mode (S0)

- **Given** one kernel kept alive and not reset, **when** it serves 2,000 requests alternating two tenants, **then**
  each response carries only its own request's data and memory stays flat (< 1 MiB drift).

### US-04 — Create and manage links (S1)

- **Given** a tenant manager, **when** they create a link with a target, an optional custom code, title and expiry,
  **then** the target is normalised and guarded, the code is random base62 (7) or the lowercased custom code, unique per
  instance, and `ShortLinkCreatedEvent` is dispatched after commit (never on rollback).
- **Given** a taken, reserved or quarantined (tombstoned) custom code, **when** anyone asks for it, **then** the answer is
  the same neutral `CodeTakenException` whatever tenant owns it.
- **Given** a target that is not http(s), has userinfo, is private / loopback / link-local / metadata, is denylisted or
  is one of our own short links, **when** it is saved, **then** it is refused with its reason and (guard refusals)
  `ShortLinkTargetRefusedEvent` names the host only.
- **Given** another tenant's id, **when** find / update / disable / enable / delete is called, **then** it answers as
  missing.
- **Given** a plan limit on manual links, **when** it is reached, **then** creation fails with `QuotaExceededException`;
  e-mail links never count; with symfony/lock the check and insert are serialised per tenant.

### US-05 — Generated links for e-mail sends (S1)

- **Given** a batch of up to 200 commands of kind `email` with a source (send ref, label, position), **when**
  `createMany()` runs (system flag), **then** the links are created in one transaction with random codes, and a command
  whose (kind, ref, position) already has a live link returns that link (retried sends never duplicate).

### US-06 — Follow a short link (S2)

- **Given** an active link, **when** a visitor opens `/s/{code}` (any query string), **then** the answer is a 302 to the
  stored target with `no-store`, `no-referrer`, `noindex` and `nosniff`, no cookie and no session.
- **Given** an unknown, malformed, disabled, expired, deleted or refused (denylisted after save) code, **when** it is
  opened, **then** the answer is the same 404 bytes and headers for every reason.
- **Given** an IP over its miss limit, **when** it opens any code, **then** it gets 429 with `Retry-After`; over the hit
  limit a valid link still redirects, unlogged (or 429 with `block`).
- **Given** a click, **then** the row holds the time, link, tenant, truncated IP, browser family, referrer host,
  optional country and the machine flag; with `Sec-GPC: 1` only time, link and machine; HEAD is never logged; a broken
  click table or resolver never breaks the redirect.

## Functional requirements

| ID | Requirement |
| --- | --- |
| FR-BUNDLE-001 | The bundle class returns the `nowo_short_link` extension. |
| FR-CONFIG-001 | Strict tree: every key of contract §4 with its default; invalid values name the path; built-in kinds keep their defaults for flags a host entry omits. |
| FR-CONFIG-002 | Container parameters published for host routing / firewalls. |
| FR-WORKER-001 | One kernel kept alive serves N requests with no bleed between tenants and flat memory. |
| FR-DB-001 | Short codes are unique and compare exactly on SQLite, MySQL (`utf8mb4_bin`), MariaDB (`utf8mb4_bin`) and PostgreSQL. |
| FR-I18N-001 | Seven translation catalogues with the same keys (en, es, it, fr, pt, de, nl). |
| FR-VO-001 | Value objects of contract §3.1 validated on construction; messages name the rule, never the value. |
| FR-PORT-001 | Ports of §3.2 are framework-free interfaces with defaults; each is a public alias hosts can replace. |
| FR-CODE-001 | Random base62 codes from the CSPRNG, skipping reserved and blocklisted candidates; collisions retry and grow the length after `max_attempts` (logged). |
| FR-CODE-002 | Custom codes lowercased, `[a-z0-9-]{3,32}` without leading / trailing / double hyphen, unique per instance, neutral refusal; immutable. |
| FR-TARGET-001 | `TargetUrl` normalisation and refusal matrix (scheme, userinfo, control / whitespace / backslash, length). |
| FR-GUARD-001 | Save guard chain: self chain, OutboundUrlGuard (never private; DNS on save only when configured), denylist port. |
| FR-MANAGE-001 | Manager create / update / disable / enable / delete (tombstone) / find / list (keyset, filters) / shortUrl. |
| FR-BULK-001 | `createMany()` ≤ 200, random codes only, quota once per batch, dedupe by (kind, ref, position). |
| FR-ACCESS-001 | Access port checked per action unless `system` or `enforce_in_manager: false`; default role-based, deny without a token. |
| FR-QUOTA-001 | Quota on counted kinds only; exact under concurrency with symfony/lock. |
| FR-EVENT-001 | Events after the outermost commit, none on rollback, ids / VOs / target host only. |
| FR-DB-002 | DBAL store on the entity manager's connection; never flushes the host EntityManager; XML mapping default + attribute twin; table and index prefix; binary collation on MySQL / MariaDB; click tables by schema listener. |
| FR-URL-001 | Absolute short URLs from `public_base_url` (re-checked at runtime) or the router request context. |
| FR-WIRING-001 | Services wired by the extension; DoctrineBundle required; symfony/lock tenant lock when a lock factory exists. |
| FR-REDIRECT-001 | Redirect step order §7.1, headers §7.2, neutral 404 / 429, limiters, stateless route, guard without DNS, refusal event. |
| FR-CLICK-001 | Click payload without I/O, three write modes, store flags, GPC, country port after the response, failures contained, `ShortLinkClickedEvent`. |
| FR-ERROR-001 | Every deliberate exception implements `ShortLinkExceptionInterface`. |

## Success criteria

- **SC-001**: `make release-check` green; CI green (matrix, coverage 100 %, PHPStan max, CS, Rector, Igor, databases,
  demo smoke for both demos).

## Non-goals

See [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md#bundle-functional-scope).
