<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Lock;

use Closure;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Port\TenantLockInterface;

/**
 * Tenant lock used when symfony/lock is not installed: runs the work directly (concurrent creates may overshoot a
 * plan limit by a few links; documented in SECURITY.md).
 */
final readonly class NoTenantLock implements TenantLockInterface
{
    /**
     * {@inheritDoc}
     */
    public function synchronized(TenantKey $tenant, Closure $work): mixed
    {
        return $work();
    }
}
