<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Port;

use Closure;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;

/**
 * Serialises the quota check and the insert of one tenant across processes. Default: symfony/lock when a lock
 * factory exists (exact limits), otherwise no lock (at most a few concurrent creates overshoot; documented).
 */
interface TenantLockInterface
{
    /**
     * Runs the work while holding the tenant's lock and returns its result.
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    public function synchronized(TenantKey $tenant, Closure $work): mixed;
}
