# Roadmap

The bundle is built slice by slice from [CONTRACT.md](CONTRACT.md) §13 (contract v1.0, owner and Beacon answers in
§17). Each slice is one pull request with tests, docs and a green CI; 0.x tags per slice after their audit (Q-O2),
**1.0.0** after the REQ-SEC-004 audit and the `bundle-req-audit` at 100 % of the applicable REQs.

## Table of contents

- [Slices](#slices)
- [Releases after the slices](#releases-after-the-slices)
- [Road to 1.0.0](#road-to-100)
- [Out of scope for 1.0](#out-of-scope-for-10)

## Slices

| Slice | Content | Status |
| --- | --- | --- |
| **S0** scaffold | Composer metadata, Docker + Makefile + `release-check`, CI matrix (PHP 8.2–8.5 × Symfony 7.4 / 8.1 × SQLite / MySQL / MariaDB / PostgreSQL), PHPStan max + phpstan-frankenphp, Igor, Rector, CS, coverage gate 100 %, Spec Kit baseline, docs set, `.github`, `.cursor`, Flex recipe, demos `symfony7` + `symfony8` (FrankenPHP worker, MySQL, UiKit, FormKit), strict configuration tree | Released (0.1.0; PR #1) |
| **S1** domain + persistence | Value objects, enums, ports + defaults, code generator, reserved words / blocklist, `TargetUrl` normalisation, guard chain + OutboundUrlGuard bridge, denylist, entity (XML + attribute mapping), DBAL click tables, table prefix, `ShortLinkManager` (create, createMany with dedupe by send ref + position, update, disable, enable, delete tombstone, list, find, shortUrl), quota (+ Lock), access, events after commit | Released (0.1.0; PR #2, audit follow-ups #4) |
| **S2** redirect | Route, controller, step order §7.1, headers §7.2, neutral 404 / 429, limiters, click recorder (terminate / messenger / sync), UA classifier, referrer, GPC, country port, `ShortLinkClickedEvent`, worker test, benchmark | Released (0.1.0; PR #3) |
| **S3** stats + retention | Rollup, purge, recheck commands; stats reader; eraser (tenant, source, actor, export); Messenger message + handler (`RecordShortLinkClick`, S2) | Released (0.1.0; PR #5, audit NY fixes #6 #7) |
| **S4** UI | List, create / edit, disable / enable / delete, stats with chart + breakdowns, CSV; translations; Twig blocks; access + tenant context; quota display; Playwright e2e + screenshots | Released (0.2.0; PR #8) |
| **S5** docs + hardening | USAGE, CONFIGURATION, ARCHITECTURE, SECURITY, PRIVACY, PERFORMANCE, UPGRADING, worker audit, demo docs, README screenshots, Spec Kit deep baseline | Released (docs set 0.1.0, screenshots 0.2.0, Spec Kit deep baseline 0.3.0) |
| **Audit** | REQ-SEC-004 (public unauthenticated endpoint + permission port), `bundle-req-audit` | Not started for 1.0.0 (per-release audits up to 0.7.0 done; see [Road to 1.0.0](#road-to-100)) |

## Releases after the slices

Status on 2026-11-23: latest tag **v0.8.0**, no open pull requests, nothing merged after 0.8.0 except docs (#25).

| Version | Content | Status |
| --- | --- | --- |
| 0.3.0 | REQ sweep: `@NowoShortLinkBundle` Twig namespace, `#[AsMessageHandler]`, `#[Route]` attributes (#12, #13, #15) | Released (0.3.0) |
| 0.4.0 | `target.allow_own_host` (Beacon C4), audit SX hardening (#16; upgrade note #14) | Released (0.4.0) |
| 0.5.0 | REQ-RUNTIME-003: 409 + `CONFLICT_*` codes, transaction-doctrine runner, `docs/CONCURRENCY.md` (#17) | Released (0.5.0) |
| 0.6.0 | One live link per source (UK-SL-2), DBAL ^4 (#18) | Released (0.6.0) |
| 0.7.0 | Usage count and source retention ports (#22); demo in the UiKit platform shell (#21) | Released (0.7.0) |
| 0.7.1 | Audit YD2 follow-ups: string tenant keys in usage counts, empty erasure batch (#23) | Released (0.7.1) |
| 0.8.0 | Identity / access-key column declaration for the collation gate (REQ-SEC-006, #24) | Released (0.8.0) |

## Road to 1.0.0

Every slice of [CONTRACT.md](CONTRACT.md) §13 is released. The 1.0.0 criterion is the **Audit** row of §13: "Pass +
owner OK → 1.0.0". What is left:

1. **`bundle-req-audit` at 100 %.** The last full re-audit (2026-11-22, head `539ca19`, 0.7.1) was 123 ✅ / 19 N/A.
   Since 0.8.0 two Spec Kit cells need a docs fix: `specs/001-baseline/code-inventory.md` does not map
   `src/Infrastructure/Doctrine/ShortLinkExactKeyColumns.php` and its file counts are stale (REQ-SPECKIT-001 / 003).
2. **REQ-SEC-004 audit of the 1.0.0 candidate.** The latest security appendix covers 0.7.0 (YD, with the YD2
   findings fixed in 0.7.1); the 0.7.1 fix diff and 0.8.0 (#24) still need a delta audit up to the 1.0.0 head.
3. **Owner OK** for 1.0.0, then the release (the release standing OK applies once audited).

Nothing is announced for removal at 1.0: there are no `@deprecated` APIs, deprecated configuration keys or BC shims
in `src/`. At 1.0.0 the public PHP API (ports, services, value objects, events, exceptions), the configuration tree,
the route names and paths, the database schema and the Twig blocks become a semver commitment.

## Out of scope for 1.0

Custom domains per tenant, QR codes, UTM builder, A/B links, link preview page, abuse report form, password-protected
links, click caps, a public HTTP JSON API (Q-O13: a versioned API, disabled by default, in a later minor).
