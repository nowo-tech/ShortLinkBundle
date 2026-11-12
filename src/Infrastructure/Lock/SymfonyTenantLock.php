<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Lock;

use Closure;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Port\TenantLockInterface;
use Symfony\Component\Lock\LockFactory;

use function hash;

/**
 * Tenant lock on the host's lock factory (symfony/lock): exact plan limits under concurrent creates (contract §9).
 * The lock is created per call and released in `finally` (worker-safe).
 */
final readonly class SymfonyTenantLock implements TenantLockInterface
{
    /**
     * @param float $ttl seconds before an abandoned lock expires
     */
    public function __construct(private LockFactory $factory, private float $ttl = 30.0)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function synchronized(TenantKey $tenant, Closure $work): mixed
    {
        $lock = $this->factory->createLock('nowo_short_link.quota.' . hash('sha256', $tenant->value), $this->ttl);
        $lock->acquire(true);
        try {
            return $work();
        } finally {
            $lock->release();
        }
    }
}
