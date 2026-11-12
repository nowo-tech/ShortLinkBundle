# Performance

## Table of contents

- [Budget](#budget)
- [Regression guard in CI](#regression-guard-in-ci)
- [Production benchmark](#production-benchmark)

## Budget

Contract §7.4 (spec 230 SC-003): p95 < 20 ms for the redirect, without a session.

| Item | Budget | How |
| --- | --- | --- |
| Routing + controller | < 2 ms | Compiled routes; the 302 renders no Twig |
| Rate limiter peek + consume | < 3 ms | A fast shared pool (Redis / APCu) in `redirect.rate_limit.cache_pool`; a database or filesystem pool is unsuitable |
| Lookup | < 2 ms | One indexed row on `uniq_short_link_code` |
| Guard (no DNS) + denylist | < 1 ms | Literal checks only; the denylist port must be in memory or a host cache on the redirect stage |
| Click write | not in the budget | After the response (`terminate`) or through Messenger |

## Regression guard in CI

`RedirectTest::testOneKeptAliveKernelServesThousandsOfRedirectsWithoutBleedOrGrowth` measures the in-kernel handle time
of 5,000 redirects (one kept-alive kernel, SQLite, array cache, terminate mode) and fails when p95 reaches 20 ms. It
guards against regressions; it is not the production number.

## Production benchmark

To be recorded with the demo (FrankenPHP worker, MySQL 8, a Redis pool, 5,000 requests over 50 links) before 1.0.0
(S5); the result and the command line go here.
