# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/short-link-bundle` (`symfony-bundle`) |
| Audited revision | S2 redirect (unreleased, branch `feat/s2-redirect`) |
| Audit date | 2026-11-12 |
| Method | Manual review of every file under `src/`; PHPStan `ruleset-classic` + `ruleset-worker-strict` + `ruleset-hardening`; Igor (`composer igor`); `WorkerRequestLoopTest` (one kernel, 2,000 requests, two tenants alternating, memory drift < 1 MiB) |
| **Verdict** | ✅ **Viable** — every S1 service is `readonly` and stateless except the after-commit queue, which holds request-scoped work and drops it at every request / message boundary and on `kernel.reset` |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Rules for the next slices](#rules-for-the-next-slices)
- [Re-audit triggers](#re-audit-triggers)

## Execution model assumed

FrankenPHP worker mode boots the kernel once and serves many requests with the same container. The bundle must work in
the strictest case: **kernel kept alive and NOT reset** (`FRANKENPHP_RESET_KERNEL=false`), with no services resetter
between requests (REQ-RUNTIME-002).

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | Only `AfterCommitQueue` (events of writes that joined an open host transaction, weak-keyed by connection), cleared on `kernel.finish_request` (main), `kernel.terminate`, `console.terminate`, Messenger worker events and `kernel.reset` |
| Statics | ✅ | None |
| Randomness | ✅ | `random_int()` per character, no seeded engine kept |
| Request-dependent reads | ✅ | Access (token storage), tenant context and short-URL base (request context) are read on every call |
| Redirect | ✅ | `RedirectController`, `RedirectResolver`, `ClickRecorder`, `ClickTerminateListener` are readonly; the click payload travels on the request attribute `_nowo_short_link_click` (removed when recorded) or in a Messenger message; limiters are created per call from their factories |
| Worker tests | ✅ | `WorkerRequestLoopTest` (2,000 requests) and the functional `testOneKeptAliveKernelServesManyCallsWithoutBleedOrGrowth` (300 creates alternating two tenants, no cross-tenant read, memory flat) on both host kinds; `RedirectTest::testOneKeptAliveKernelServesThousandsOfRedirectsWithoutBleedOrGrowth` (5,000 redirects over two tenants and three states, terminate mode, memory flat, in-kernel p95 < 20 ms) |
| Boot-time caches | ✅ | `NowoShortLinkBundle::getContainerExtension()` keeps its extension (Symfony's own pattern, `@igor-ignore`) |
| Worker test | ✅ | `tests/Integration/Worker/WorkerRequestLoopTest.php` |

## Rules for the next slices

From contract §7.5:

- Every service `readonly`; no memo of codes, links, tenants, IPs or limiter state in properties or statics.
- Per-request data (the click payload) on `Request` attributes only, cleared on `kernel.finish_request`.
- The UA classifier and the reserved / blocklist sets are immutable compiled arrays (container parameters).
- Own DBAL usage: no transaction left open; reconnect-safe.
- With the default EntityManager, the manager detaches only its own entities after each write unit; with a dedicated
  one, `clear()` after each unit and recreate a closed EntityManager.
- `Random\Randomizer(new Random\Engine\Secure())` per call; no seeded engine kept in a service.
- The worker test grows to 5,000 alternating requests over two tenants and three link states (S2).

## Re-audit triggers

Any new service, listener, static, cache or Messenger handler; any change to the redirect path.
