# Feature Specification: ShortLinkBundle baseline

**Feature Branch**: `001-baseline`
**Created**: 2026-11-12
**Status**: Baseline (S0 scaffold)
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

## Functional requirements

| ID | Requirement |
| --- | --- |
| FR-BUNDLE-001 | The bundle class returns the `nowo_short_link` extension. |
| FR-CONFIG-001 | Strict tree: every key of contract §4 with its default; invalid values name the path; built-in kinds keep their defaults for flags a host entry omits. |
| FR-CONFIG-002 | Container parameters published for host routing / firewalls. |
| FR-WORKER-001 | One kernel kept alive serves N requests with no bleed between tenants and flat memory. |
| FR-DB-001 | Short codes are unique and compare exactly on SQLite, MySQL (`utf8mb4_bin`), MariaDB (`utf8mb4_bin`) and PostgreSQL. |
| FR-I18N-001 | Seven translation catalogues with the same keys (en, es, it, fr, pt, de, nl). |

## Success criteria

- **SC-001**: `make release-check` green; CI green (matrix, coverage 100 %, PHPStan max, CS, Rector, Igor, databases,
  demo smoke for both demos).

## Non-goals

See [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md#bundle-functional-scope).
