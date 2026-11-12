<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Port;

use Nowo\ShortLinkBundle\Domain\Model\ActorRef;

/**
 * Per-person limit on "this code is not available" answers (contract §6.2, audit L7): a custom code that is taken,
 * reserved or quarantined must not become an enumeration oracle. The manager asks {@see self::allows()} before it looks
 * a custom code up and calls {@see self::hit()} after each "not available" answer. Default: symfony/rate-limiter on
 * `redirect.rate_limit.cache_pool` (`code.taken_rate_limit`, 10 per minute). Implementations keep no state in the
 * service (worker mode).
 */
interface CodeTakenLimiterInterface
{
    /**
     * Whether this person may still get a "not available" answer (consumes nothing). Null actor = an anonymous caller.
     */
    public function allows(?ActorRef $actor): bool;

    /**
     * Counts one "not available" answer for this person.
     */
    public function hit(?ActorRef $actor): void;
}
