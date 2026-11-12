# Security

## Table of contents

- [Status and scope](#status-and-scope)
- [Attack surface](#attack-surface)
- [Threat model](#threat-model)
- [Controls by slice](#controls-by-slice)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [Audits](#audits)
- [Reporting](#reporting)

## Status and scope

S1–S4: the manager, the ports, the target guard, the DBAL store, the public redirect `GET|HEAD /s/{code}` with its
click log, and the opt-in management UI (S4, behind the host's firewall and the access port).

**Covers (from S1):** how short codes are generated and reserved, how targets are validated at save and at redirect
time, how the public redirect answers, what each click stores and for how long, and how tenants are isolated.
**Does not cover:** the host's sign-in, sessions and permission model (reached through the access port), transport
security (HTTPS is the host's job) and the content of the target sites.

## Attack surface

| Input | Where (slice) | Handling |
| --- | --- | --- |
| Configuration | `nowo_short_link` tree (S0) | Strict (unknown keys refused), validated paths, URLs, durations, kinds, prefixes |
| `{code}` of the public redirect | `GET|HEAD /s/{code}` (S2) | Route requirement `[A-Za-z0-9-]{3,32}` before any I/O; one indexed lookup + PHP `===` re-check; identical neutral 404 for every failure |
| Target URL | Manager / UI form (S1, S4) | `TargetUrl` VO (http/https, absolute, no userinfo, no control characters, ≤ 2 048 bytes, IDN → punycode); guard with DNS at save, literal checks + denylist on every redirect |
| Request headers | Redirect (S2) | `User-Agent` (first 512 bytes, bounded regex list), `Referer` (host only), `Sec-GPC`, client IP (truncated, never stored raw) |
| UI forms and actions | Management UI (S4) | FormKit Form Types (no `system` / `kind` field, extra fields refused), CSRF per action, POST only for changes, View / Manage per page, tenant-scoped lookups (another tenant's id → 404) |
| CSV export | Statistics (S4) | Cells starting with `=`, `+`, `-`, `@`, tab or CR (also after leading spaces) prefixed with `'`; range bounded by `ui.csv_max_days` |
| Host ports | Tenant context, access, quota, denylist, country | Host code; the bundle fails closed (no tenant → 404, denied access → 404 / 403) |

## Threat model

From contract §9. Each threat names the control and the slice that ships it.

| Threat | Control | Slice |
| --- | --- | --- |
| Open redirect via parameters | Only `{code}`; no `?url=`; target from the database | S2 |
| Phishing / malware under the trusted domain | Manage permission per tenant, plan limit, denylist port, refused-target event, instant disable, recheck command, audit events | S1–S3 |
| Intranet pivot from the visitor's browser | Private, loopback, link-local, CGNAT, metadata targets refused at save (DNS) and at redirect (literals) | S1, S2 |
| Scheme abuse, header injection | VO: http/https only, no control characters, no userinfo | S1 |
| Redirect chains | `self_paths` on `own_hosts` refused | S1 |
| Enumeration | 62^7 space, miss limiter, identical 404, no timing branch, neutral `CodeTaken`, per-person custom-code limiter | S1, S2, S4 |
| Code collision / takeover | Unique index, binary NO PAD collation (`utf8mb4_0900_bin` / `utf8mb4_nopad_bin`), PHP `===`, tombstone quarantine (365 days), immutable codes | S0 (test), S1 |
| Cross-tenant access | Every query filters on the tenant; another tenant's id answers as missing | S1–S4 |
| CSRF, XSS, CSV injection | FormKit CSRF forms, Twig escaping, CSV cell prefixing | S4 |
| Session / cookie on the public path | `stateless: true`, no `Set-Cookie` | S2 |
| Log flooding, bots | Hit limiter, HEAD unlogged, machine flag, retention P90D | S2, S3 |
| Plan-limit race | Count + insert under a per-tenant lock (`symfony/lock`) | S1 |

## Controls by slice

- **S0:** strict configuration; no service registered; GitGuardian-safe repository (no credential-looking strings,
  passwordless CI and demo databases); the database suite proves case-sensitive unique codes on SQLite, MySQL, MariaDB
  and PostgreSQL.
- **S1:** `TargetUrl` refusal matrix (scheme, userinfo, control / whitespace / backslash, length, IDN to punycode);
  save guard (self chain, OutboundUrlGuard with `allowPrivate` always false and DNS on save when configured, denylist);
  neutral `CodeTakenException` for taken / reserved / quarantined codes; tombstones keep deleted codes; exact unique
  codes (binary collation + PHP `===`); tenant filter + PHP re-check on every read; access checked per action (anonymous
  denied by default; `system` only for programmatic callers); quota serialised per tenant with symfony/lock; events after
  commit with the target host only; logs carry the tenant and counts, never codes, targets or IPs.
- **Audit NW follow-ups (S1):** L1 self-chain normalisation (full percent-decoding as the router does, `%73%2F`, dot segments, public host); L2 `D` modifier on
  every anchored pattern; L5 counted creates refused inside a host transaction + a 10-process parallel test that
  creates exactly the limit (MySQL / MariaDB / PostgreSQL CI); L8 hidden kinds never listed for people; L9 no
  invisible / bidi characters, ASCII-only targets.
- **Known gaps:** without symfony/lock, concurrent creates may overshoot a plan limit by a few links.
- **S2:** the redirect takes only `{code}` (query ignored, `Location` from the database, no CRLF possible); one
  neutral 404 for every reason after the same single query; miss limiter peeked before the lookup; hit limiter with
  redirect-unlogged / block; HEAD never logged; stateless route, no cookie; redirect guard on every answer (literals +
  denylist, no DNS) with a refusal event; no raw IP stored or logged (only the truncated prefix and, for the country
  resolver, the raw IP in memory after the response); GPC honoured; recorder and Messenger failures contained.
- **Audit NW (S2):** M1 limiter key per IPv6 /64 (configurable /56–/64), IPv4-mapped / 6to4 folded; M2 every event
  dispatch and log call on the redirect path guarded, refused event deferred to `kernel.terminate`; L3 minimal
  hot-path query, state decided before hydration; L4 unreadable rows → 404 + warning; L6 6to4 / Teredo prefixes.
- **Audit NY (S3):** M1 recheck narrow update + events only on a changed row; M2 DNS failures skipped, circuit breaker
  (`target.recheck_max_unresolvable_percent`), `--dry-run`; M3 rolled days at or before the retention cutoff are never
  recomputed, retention / lag / rollup-retention checked at build; M4 erasure tombstones first (one transaction when
  possible), late clicks of erased / deleted / foreign links dropped; L1 recheck cursor; L2 rollup lock + day index;
  L3 / L4 blanked source tombstones and rollups in the export; self-chain guard collapses `//`.
- **Audit NY re-verification:** N1 cumulative recheck breaker with a minimum sample, non-DNS refusals applied before
  an abort; N2 retry-safe erasure order; N3 second click-delete pass; I1 liveness check only for Messenger clicks;
  I2 links in the export, tombstone rollups purged; I3 rollup deadlocks skipped.
- **S4 (UI):** tenant from the context port on every page (none → 404), access port per action (View for pages,
  Manage for changes; denial → 403), lookups by id scoped by tenant (another tenant's id → 404, never 403); POST + one
  CSRF token per state change (missing / wrong → 403, nothing changed); forms never map `system` / `kind` (extra fields
  refused); audits L7 / NY-S4 L2–L3 limit on custom-code lookups per tenant + actor (`code.custom_code_rate_limit`,
  attempt consumed before the lookup, atomic with symfony/lock, hashed key; actor-less callers share one window per
  tenant unless the host replaces the port); NY-S4 L1 kinds hidden from people answer 404 by id on every page and
  action; the tenant route parameter only builds URLs (the host's tenant context must authorise it);
  Twig escaping everywhere (title, target, referrer hosts), target linked only when http(s) with
  `rel="noopener noreferrer nofollow"`; CSV formula neutralisation; no personal data logged by the UI; stateless
  controller and services (worker mode). Fixtures: XSS title / referrer, CSV `=HYPERLINK`, cross-tenant matrix.
- **PAD SPACE sweep (2026-11-12, affects 0.1.0, no advisory):** `ActorRef` refuses padded ids and control / format
  characters; `anonymiseActor()` re-checks candidates with `===` and updates by primary key; identifier columns use
  NO PAD binary collations (`utf8mb4_0900_bin` / `utf8mb4_nopad_bin`; `utf8mb4_bin` only on MySQL 5.7). Test emulates a
  PAD collation (MySQL `utf8mb4_bin`, SQLite `RTRIM`).
- **S5:** see the contract.

## Release security checklist (12.4.1)

1. REQ-SEC-004 audit of the slice recorded with a passing grade; blocking findings fixed and re-checked.
2. No secret, token, raw IP or full target URL with a query string in logs, events, exceptions or Messenger messages.
3. `composer audit` clean (CI); dependencies current.
4. Worker audit current ([FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md)).

## Audits

- S2 (public redirect) and the guard work of S1 need an **immediate REQ-SEC-004 audit** before a tag that contains
  them; the checklist handed to the auditor is contract §14.1.
- 1.0.0 also needs the `bundle-req-audit` at 100 % of the applicable REQs.

## Reporting

See [.github/SECURITY.md](../.github/SECURITY.md): report privately to hectorfranco@nowo.tech.
