<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Persistence;

use Nowo\ShortLinkBundle\Domain\Port\TransientFailureDetectorInterface;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Throwable;

/**
 * Default {@see TransientFailureDetectorInterface}: transaction-doctrine's {@see TransientFailureClassifier} (driver
 * error codes of DBAL lock errors, never the exception message). Stateless (worker mode).
 */
final readonly class DbalTransientFailureDetector implements TransientFailureDetectorInterface
{
    /**
     * {@inheritDoc}
     */
    public function isTransient(Throwable $failure): bool
    {
        return (new TransientFailureClassifier())->isTransient($failure);
    }
}
