# Privacy

## Table of contents

- [Data inventory](#data-inventory)
- [Retention](#retention)
- [E-mail links](#e-mail-links)
- [Erasure and export](#erasure-and-export)

## Data inventory

| Data | Where | Personal? |
| --- | --- | --- |
| Truncated IP (`/24`, IPv6 `/48`), browser family, referrer host, country, time, machine flag | `nowo_short_link_click` | Pseudonymous; no raw IP, full User-Agent, cookie or user id is ever stored; `Sec-GPC: 1` keeps the time only |
| Daily counts per link and per referrer host / country / browser family | `nowo_short_link_click_daily` | Aggregates; for a link sent to one person (e-mail kind) they still describe that person |
| `created_by` / `updated_by` | `nowo_short_link` | The host's users (`anonymiseActor()`) |

## Retention

- Raw clicks: `click_log.retention` (default `P90D`), deleted by `nowo:short-link:purge` once their day is rolled. It
  must be at least `click_log.rollup.lag_days` + 1 days.
- Rollups: `click_log.rollup.retention` (default: kept). It must be at least `click_log.retention`.
- Tombstones: `code.quarantine` (default `P365D`); purging a tombstone also deletes its remaining clicks and rollups.

## E-mail links

E-mail-kind links usually reach one recipient, so their rollup rows remain personal data for as long as they are kept.
Either set `click_log.rollup.retention` (e.g. `P13M`), or erase each send with `eraseSource()` when the host's own
retention for the send ends; `exportSource()` includes the rollup rows.

## Erasure and export

`ShortLinkEraserInterface` (see [USAGE.md](USAGE.md#personal-data-gdpr)): tombstones first, then clicks and rollups
(one transaction when both tables share a connection; the order is retry-safe, so a failed run can simply be run
again); erased codes stay taken until the quarantine ends. Queued Messenger clicks that arrive later are dropped by
the recorder, and the erasure deletes clicks a second time at its end. The eraser dispatches no `ShortLinkDeletedEvent`.
