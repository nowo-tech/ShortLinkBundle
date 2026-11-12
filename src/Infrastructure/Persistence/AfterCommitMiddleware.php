<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Persistence;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * DBAL middleware (tag `doctrine.middleware`, every connection): tells the {@see AfterCommitQueue} when a connection's
 * outermost transaction commits or rolls back. Nested levels use savepoints and never reach the driver, so the driver
 * commit is exactly the outermost one.
 *
 * @internal
 */
final readonly class AfterCommitMiddleware implements Middleware
{
    /**
     * Holds the queue.
     */
    public function __construct(private AfterCommitQueue $queue)
    {
    }

    /**
     * Wraps the driver.
     */
    public function wrap(Driver $driver): Driver
    {
        $queue = $this->queue;

        return new class($driver, $queue) extends AbstractDriverMiddleware {
            /**
             * Wraps the driver and keeps the queue.
             */
            public function __construct(Driver $driver, private readonly AfterCommitQueue $queue)
            {
                parent::__construct($driver);
            }

            /**
             * Connects and wraps the connection in an {@see AfterCommitConnection}.
             */
            public function connect(array $params): Driver\Connection
            {
                return new AfterCommitConnection(parent::connect($params), $this->queue);
            }
        };
    }
}
