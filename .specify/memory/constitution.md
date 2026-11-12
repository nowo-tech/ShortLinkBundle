# ShortLinkBundle Constitution

## Core Principles

### I. Documented integrator contract
Product behaviour lives in `docs/CONTRACT.md` (contract v1.0, spec 230 Block A, owner-approved 2026-11-12), `specs/001-baseline/spec.md`,
`docs/SPEC-DRIVEN-DEVELOPMENT.md` and the integrator docs (`USAGE.md`, `CONFIGURATION.md`). Demos are illustrative
unless promoted in the spec.

### II. Spec-first, test-proven
PHPUnit and PHPStan (and Vitest when frontend exists) are the mechanical proof. Behavioural changes require tests.

### III. 100% code inventory traceability
Every production file under `src/` must appear in `specs/001-baseline/code-inventory.md`. New files require spec
updates in the same PR.

### IV. Cursor + Spec Kit
GitHub Spec Kit is initialized with **Cursor Agent** (`cursor-agent`). Skills live in `.cursor/skills/speckit-*`.

### V. Symfony compatibility
Follow declared PHP/Symfony ranges in `composer.json` and README badges.

### VI. Standard by default, DDD opt-in
Works out of the box in a standard Symfony project; usable from a strict DDD / hexagonal host (plain-PHP domain,
Doctrine XML mapping, references by id). Public contracts are interfaces + value objects; the bundle never flushes or
commits the host's EntityManager; events are dispatched after commit and carry ids. Both host kinds are tested.

### VII. Worker-safe, exact, fail closed
FrankenPHP worker mode with the kernel kept alive and not reset: no request state in services, memos implement
`ResetInterface` and are cleared at the request boundary, an N-request test proves flat memory and no bleed between
two tenants. Identifier and code columns compare exactly. The UI and every opt-in feature are off by default; access is denied by
default.

## Governance
Amendments update this file, baseline spec when principles affect behavior, and `CHANGELOG.md` when consumer-visible.

**Version**: 1.0.0 | **Ratified**: 2026-11-12 | **Last Amended**: 2026-11-12
