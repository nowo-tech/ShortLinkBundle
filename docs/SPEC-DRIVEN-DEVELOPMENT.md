# Spec-driven development

This document is the single entry point for **what ShortLinkBundle guarantees**, **how it is proven** and **which
`REQ-*` anchors** the Makefiles and demos carry. There is no separate executable spec language: **PHPUnit** and
**PHPStan** are the normative proof alongside the prose.

## Table of contents

- [Layers kept in sync](#layers-kept-in-sync)
- [User stories](#user-stories)
- [Bundle functional scope](#bundle-functional-scope)
- [Validating the functional spec](#validating-the-functional-spec)
- [Requirement identifiers](#requirement-identifiers)
- [Contributor workflow](#contributor-workflow)
- [Relationship to Engram](#relationship-to-engram)
- [GitHub Spec Kit](#github-spec-kit)
- [See also](#see-also)

## Layers kept in sync

| Layer | Role | Where |
| --- | --- | --- |
| Design contract | Contract v1.0 (spec 230 Block A), owner-approved 2026-11-12 (every Q-O and Q-B as recommended): ports, persistence, security model, configuration, worker design, tests, slices | [CONTRACT.md](CONTRACT.md), [ROADMAP.md](ROADMAP.md) |
| GitHub Spec Kit baseline | Feature spec (user scenarios, `FR-*`, `SC-*`, entities, non-goals) and the 100 % code inventory of `src/` | [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md), [`specs/001-baseline/code-inventory.md`](../specs/001-baseline/code-inventory.md), manual in [SPEC-KIT.md](SPEC-KIT.md) |
| Product behaviour | What integrating applications may rely on | [USAGE.md](USAGE.md), [CONFIGURATION.md](CONFIGURATION.md), [INSTALLATION.md](INSTALLATION.md), [SECURITY.md](SECURITY.md) |
| Traceability anchors | Stable `REQ-*` ids in the Makefiles, CI and demo files, so PRs and issues can cite tooling behaviour | [Requirement identifiers](#requirement-identifiers) |
| Change management | Consumer-facing deltas | [CHANGELOG.md](CHANGELOG.md), [UPGRADING.md](UPGRADING.md), [RELEASE.md](RELEASE.md) |

Every slice updates the baseline spec, the code inventory and the integrator docs in the same pull request.

## User stories

| ID | Story | Scope | Integrator docs |
| --- | --- | --- | --- |
| US-S0-01 | **As an** integrator, **I want** to install the package and write the final configuration now **so that** later slices only switch features on. | Strict config tree, parameters | [CONFIGURATION.md](CONFIGURATION.md) |
| US-S0-02 | **As a** maintainer, **I want** every quality gate (tests, coverage 100 %, PHPStan + worker rules, Igor, CS, Rector, demo smoke) from the first PR **so that** no slice lowers the bar. | Makefile, CI | [CONTRIBUTING.md](CONTRIBUTING.md), [GITHUB_CI.md](GITHUB_CI.md) |
| US-S0-03 | **As a** platform maintainer, **I want** a worker-mode test (one kernel, many requests) from S0 **so that** each stateful slice extends it. | `WorkerRequestLoopTest` | [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md) |
| US-S1-01 | **As a** tenant manager, **I want** to create a short link with a random or custom code for an allowed target **so that** I can share a short, stable URL. | Manager, code generator, guard (S1) | [USAGE.md](USAGE.md) |
| US-S1-02 | **As** MailKit / Beacon, **I want** to create the links of one e-mail send in bulk, deduplicated by send ref + position **so that** a retried send never duplicates links. | `createMany()` (S1) | [USAGE.md](USAGE.md) |
| US-S2-01 | **As a** visitor, **I want** `/s/{code}` to send me to the target fast, without a cookie **so that** short links behave like plain links. | Redirect (S2) | [USAGE.md](USAGE.md#redirect-path-and-firewalls) |
| US-S3-01 | **As a** tenant manager, **I want** daily clicks, top referrers, countries and browser families with machine traffic apart **so that** I know who used a link without tracking people. | Click log, rollup, stats (S2, S3) | [CONTRACT.md](CONTRACT.md) |
| US-S4-01 | **As a** tenant manager, **I want** a UiKit page to list, create, edit, disable and delete links and see their stats **so that** I need no code. | UI (S4) | [CONTRACT.md](CONTRACT.md) |

## Bundle functional scope

**Goal:** short links for Symfony applications: per-tenant codes behind a public stateless redirect, a target guard, a
privacy-preserving click log with rollups and retention, a PHP API for generated links and a UiKit + FormKit UI.

**In scope (1.0):** contract v1.0 §3–§12 ([CONTRACT.md](CONTRACT.md)). **S0** ships the bundle class and the strict
configuration tree.

**Explicit non-goals (v1):** custom domains per tenant, QR codes, UTM builder, A/B links, link preview page, abuse
report form, password-protected links, click caps, a public HTTP JSON API (Q-O13), link rewriting and open pixels in
e-mails (MailKitBundle's tracking ports).

**Not in the Packagist API:** `demo/` (illustrative only, excluded from the archive), `tests/` and the `@internal`
classes.

## Validating the functional spec

```bash
make test-coverage-100   # PHPUnit (Unit, Integration, Database on SQLite) + 100 % statements / elements of src/
make test-with-db        # Database suite on MySQL 8.4 (REQ-TEST-005); CI adds MariaDB 10.11 and PostgreSQL 17
make phpstan igor        # PHPStan level max + FrankenPHP worker rulesets, Igor worker-state audit
make qa                  # cs-check + test
make release-check       # the whole pre-release chain, demo included
```

Coverage floor: **100 %** of `src/` (statements and elements; CI gate). Any behaviour change ships with tests under
`tests/` (unit for value objects and services, functional for both host kinds, worker loop for state).

## Requirement identifiers

| `REQ-*` | Where | Meaning |
| --- | --- | --- |
| REQ-MAKE-001 / 002 | `Makefile` (`help`, `ensure-up`, `release-check`) | Standard targets, sequential release chain |
| REQ-MAKE-003 | `demo/symfony7/Makefile`, `demo/symfony8/Makefile` | Demo targets incl. `test-coverage`, `verify` |
| REQ-MAKE-007 | `Makefile` `down-dev` | Stop without removing volumes |
| REQ-MAKE-008 | `Makefile`, `demo/Makefile`, `demo/symfony*/Makefile` (soft includes) | `update-deps`, `update-deps-all` |
| REQ-MAKE-004 / REQ-I18N-002 | `Makefile` `validate-translations` | YAML syntax + key parity of the 7 locales |
| REQ-TEST-003 / 006 | `Makefile` `test-coverage-100`, `coverage-check` | 100 % gate |
| REQ-TEST-005 | `Makefile` `test-with-db`, `test-coverage-with-db`; `docker-compose.yml` service `database` (profile `db`) | Database suite on MySQL |
| REQ-TEST-011 | `Makefile` / `demo/Makefile` `demo-smoke`, `.github/workflows/demo-smoke.yml` | Demo boots, HTTP 200 |
| REQ-CS-001 | `Makefile` `validate-phpdoc` | PHPDoc on every class and public method |
| REQ-CS-008 | `Makefile` `igor` | Igor worker-state audit |
| REQ-TWIG-004 / 005 | `Makefile` `check-twig-extra`, `check-no-raw-html-form` | Twig extras, no raw form tags |
| REQ-REL-003 | `Makefile` `check-open-prs` | No open PRs left at release |
| REQ-GIT-001 | `Makefile` `setup-hooks`, CI job "Git history" | No agent co-author trailers |
| REQ-DEMO-001 / 002 / 010 / 012 | `demo/symfony*/composer.json`, `config/bundles.php`, `docker/frankenphp/Caddyfile*`, `docker/entrypoint.sh` | Dev stack, FrankenPHP + Mercure + hot reload, `FRANKENPHP_MODE`, Symfony 7.4 / 8.1 |
| REQ-DEMO-014 / 015 | `demo/symfony*/templates/base.html.twig`, `config/packages/nowo_ui_kit.yaml`, `nowo_form_kit.yaml` | UiKit admin layout + default theme (no CDN), FormKit `demo` profile |

When scripted behaviour changes, update the matching anchor comment and this table in the same pull request.

## Contributor workflow

1. **Clarify** the behaviour against [CONTRACT.md](CONTRACT.md) and the baseline spec (add or adjust `US-*`, `FR-*`,
   `SC-*` first).
2. **Implement with tests**: a failing test first where practical; keep the 100 % gate, PHPStan, Igor and the worker
   test green.
3. **Anchor scripts**: tooling changes keep or add their `REQ-*` comment.
4. **Ship integrator docs**: USAGE / CONFIGURATION / SECURITY / CHANGELOG / UPGRADING whenever behaviour or
   configuration changes; refresh `specs/001-baseline/code-inventory.md` when `src/` files change.

## Relationship to Engram

[ENGRAM.md](ENGRAM.md) covers the organisation-wide plane (Engram MCP memory, `.cursor/mcp.json`, checklist hygiene).
This document covers the product plane (behaviour and traceability). Neither replaces the other; Engram points here
for local `REQ-*` traceability.

## GitHub Spec Kit

The repository is initialised for GitHub Spec Kit (`.specify/`, Cursor Agent skills in `.cursor/skills/speckit-*`).
The full manual (install, initialise, navigate `.specify/` vs `specs/`, skills) is [SPEC-KIT.md](SPEC-KIT.md). The
baseline lives in [`specs/001-baseline/`](../specs/001-baseline/): `spec.md` (scenarios, requirements, success
criteria) and `code-inventory.md` (every file of `src/` mapped to `FR-*`). Keep the inventory aligned with `src/` in
every pull request that adds, moves or removes a source file.

## See also

- [GitHub Spec Kit](SPEC-KIT.md)
- [Usage](USAGE.md) · [Configuration](CONFIGURATION.md) · [Installation](INSTALLATION.md)
- [Contributing](CONTRIBUTING.md) · [Release](RELEASE.md) · [Roadmap](ROADMAP.md)
- [Demo with FrankenPHP](DEMO-FRANKENPHP.md) · [FrankenPHP worker audit](FRANKENPHP-WORKER-AUDIT.md)
