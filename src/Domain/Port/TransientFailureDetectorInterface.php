<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Port;

use Throwable;

/**
 * Tells whether a storage failure is a lock conflict worth retrying (deadlock, lock wait timeout, serialization
 * failure), walking the previous chain. The container wires `Infrastructure\Persistence\DbalTransientFailureDetector`
 * (transaction-doctrine's classifier), so the Application layer stays framework-free (REQ-ARCH-002).
 */
interface TransientFailureDetectorInterface
{
    /**
     * Whether replaying the failed write can succeed.
     */
    public function isTransient(Throwable $failure): bool;
}
