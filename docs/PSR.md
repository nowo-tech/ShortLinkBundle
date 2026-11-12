# PSR compliance

Package: `nowo-tech/short-link-bundle` (`symfony-bundle`)

| PSR | Status | Notes |
| --- | --- | --- |
| PSR-1 / PSR-12 Coding style | **Adopted** | PHP-CS-Fixer (`@PSR12` + `@Symfony`), `make cs-check` in CI |
| PSR-4 Autoloading | **Adopted** | `Nowo\ShortLinkBundle\` → `src/` |
| PSR-3 Logger | **Adopted** (S1) | Manager, store, after-commit queue (and later the recorder and commands) take an optional `Psr\Log\LoggerInterface` and log link ids, refusal kinds and exception classes only, never full target URLs, IPs or code lists (contract §14.1 item 14) |
| PSR-6 / PSR-16 Cache | **N/A** | The S2 redirect limiters use a Symfony cache pool (`redirect.rate_limit.cache_pool`) through `symfony/rate-limiter` |
| PSR-14 Event dispatcher | **Adopted** (S1) | Domain events (`ShortLinkCreatedEvent`, …) are plain classes dispatched after commit through `Psr\EventDispatcher\EventDispatcherInterface`, ids and value objects only |
| PSR-20 Clock | **Adopted** (S1) | Expiry and timestamps read an injected `Psr\Clock\ClockInterface` (`clock` service); tests use `MockClock` |
| PSR-7 / PSR-15 / PSR-17 / PSR-18 | **N/A** | No HTTP client (the bundle never fetches a target) and no PSR-7 messages: the redirect uses Symfony HttpFoundation |
