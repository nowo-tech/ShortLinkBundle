# Roadmap

The bundle is built slice by slice from [CONTRACT.md](CONTRACT.md) §13 (contract v1.0, owner and Beacon answers in
§17). Each slice is one pull request with tests, docs and a green CI; 0.x tags per slice after their audit (Q-O2),
**1.0.0** after the REQ-SEC-004 audit and the `bundle-req-audit` at 100 % of the applicable REQs.

## Table of contents

- [Slices](#slices)
- [Out of scope for 1.0](#out-of-scope-for-10)

## Slices

| Slice | Content | Status |
| --- | --- | --- |
| **S0** scaffold | Composer metadata, Docker + Makefile + `release-check`, CI matrix (PHP 8.2–8.5 × Symfony 7.4 / 8.1 × SQLite / MySQL / MariaDB / PostgreSQL), PHPStan max + phpstan-frankenphp, Igor, Rector, CS, coverage gate 100 %, Spec Kit baseline, docs set, `.github`, `.cursor`, Flex recipe, demos `symfony7` + `symfony8` (FrankenPHP worker, MySQL, UiKit, FormKit), strict configuration tree | Merge-ready (PR #1) |
| **S1** domain + persistence | Value objects, enums, ports + defaults, code generator, reserved words / blocklist, `TargetUrl` normalisation, guard chain + OutboundUrlGuard bridge, denylist, entity (XML + attribute mapping), DBAL click tables, table prefix, `ShortLinkManager` (create, createMany with dedupe by send ref + position, update, disable, enable, delete tombstone, list, find, shortUrl), quota (+ Lock), access, events after commit | Released (0.1.0) |
| **S2** redirect | Route, controller, step order §7.1, headers §7.2, neutral 404 / 429, limiters, click recorder (terminate / messenger / sync), UA classifier, referrer, GPC, country port, `ShortLinkClickedEvent`, worker test, benchmark | Released (0.1.0) |
| **S3** stats + retention | Rollup, purge, recheck commands; stats reader; eraser (tenant, source, actor, export); Messenger message + handler (`RecordShortLinkClick`, S2) | Released (0.1.0) |
| **S4** UI | List, create / edit, disable / enable / delete, stats with chart + breakdowns, CSV; translations; Twig blocks; access + tenant context; quota display; Playwright e2e + screenshots | In review (PR, target 0.2.0) |
| **S5** docs + hardening | USAGE, CONFIGURATION, ARCHITECTURE, SECURITY, PRIVACY, PERFORMANCE, UPGRADING, worker audit, demo docs, README screenshots, Spec Kit deep baseline | |
| **Audit** | REQ-SEC-004 (public unauthenticated endpoint + permission port), `bundle-req-audit` | 1.0.0 |

## Out of scope for 1.0

Custom domains per tenant, QR codes, UTM builder, A/B links, link preview page, abuse report form, password-protected
links, click caps, a public HTTP JSON API (Q-O13: a versioned API, disabled by default, in a later minor).
