<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Persistence;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;

use function is_object;

/**
 * Driver connection of {@see AfterCommitMiddleware}: reports the outermost commit / rollback to the queue.
 *
 * @internal
 */
final class AfterCommitConnection extends AbstractConnectionMiddleware
{
    /**
     * Wraps a driver connection.
     */
    public function __construct(Connection $wrapped, private readonly AfterCommitQueue $queue)
    {
        parent::__construct($wrapped);
    }

    /**
     * Commits, then runs the work held for this connection.
     */
    public function commit(): void
    {
        parent::commit();
        $native = $this->getNativeConnection();
        if (is_object($native)) {
            $this->queue->committed($native);
        }
    }

    /**
     * Rolls back and drops the work held for this connection (also when the rollback itself fails).
     */
    public function rollBack(): void
    {
        try {
            parent::rollBack();
        } finally {
            $native = $this->getNativeConnection();
            if (is_object($native)) {
                $this->queue->rolledBack($native);
            }
        }
    }
}
