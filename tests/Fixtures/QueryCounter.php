<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Fixtures;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use SensitiveParameter;

/**
 * Test DBAL middleware: counts the statements sent to the database (prepare and query; DBAL 3 / 4). The test resets
 * the counter before the request it measures (REQ-PERF-001 query budget).
 */
final class QueryCounter implements Middleware
{
    public int $count = 0;

    public function wrap(Driver $driver): Driver
    {
        return new class($driver, $this) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly QueryCounter $counter)
            {
                parent::__construct($driver);
            }

            public function connect(#[SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params), $this->counter) extends AbstractConnectionMiddleware {
                    public function __construct(Connection $connection, private readonly QueryCounter $counter)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        ++$this->counter->count;

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        ++$this->counter->count;

                        return parent::query($sql);
                    }
                };
            }
        };
    }
}
