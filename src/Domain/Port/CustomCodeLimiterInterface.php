<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Port;

use Nowo\ShortLinkBundle\Domain\Model\ActorRef;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;

/**
 * Per-person limit on custom-code lookups (contract §6.2, audits L7 / NY-S4 L2–L3): a custom code that is taken,
 * reserved or quarantined must not become an enumeration oracle. The manager calls {@see self::attempt()} before it
 * looks a custom code up, for people only (never for `system` commands); the attempt is consumed whatever the answer,
 * so concurrent requests cannot all pass a peek before any of them is counted.
 *
 * Default: symfony/rate-limiter fixed window (`code.custom_code_rate_limit`) keyed by tenant + actor, atomic across
 * processes when symfony/lock is installed. Callers without an actor share one window per tenant; a host that knows
 * a better key for them (session, client network) replaces the port (`code.custom_code_rate_limit.limiter`).
 * Implementations keep no state in the service (worker mode).
 */
interface CustomCodeLimiterInterface
{
    /**
     * Takes one attempt for this person in this tenant; false = over the limit (nothing is looked up). Null actor =
     * a caller the access port does not identify.
     */
    public function attempt(TenantKey $tenant, ?ActorRef $actor): bool;
}
