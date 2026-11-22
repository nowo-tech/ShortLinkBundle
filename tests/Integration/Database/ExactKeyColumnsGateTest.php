<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Nowo\MigrationsKitBundle\Test\ExactKeyColumnsTestCase;
use Nowo\ShortLinkBundle\Infrastructure\Doctrine\ShortLinkExactKeyColumns;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\Host;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\TestKernel;
use Symfony\Component\Filesystem\Filesystem;

/**
 * REQ-SEC-006 gate of this bundle: every declared identity / access-key column of a freshly created schema is in a
 * binary NO PAD collation on the test database (MySQL 8.4 and MariaDB 11.4 in CI; skipped on SQLite and PostgreSQL,
 * which compare exactly already).
 *
 * The schema is created through the DDD host, whose `doctrine.table_prefix` is `ddd_`: the run therefore also proves
 * the declaration follows the configured table names instead of a literal.
 */
final class ExactKeyColumnsGateTest extends ExactKeyColumnsTestCase
{
    private Host $host;

    private ?TestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->host = Host::ddd();
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        (new Filesystem())->remove(TestKernel::baseDir());
    }

    /**
     * The database suite's DSN (`NOWO_TEST_DATABASE_URL`), not Symfony's `DATABASE_URL`.
     */
    protected function databaseUrlVariable(): string
    {
        return 'NOWO_TEST_DATABASE_URL';
    }

    /**
     * {@inheritdoc}
     */
    protected function exactKeyColumnProviders(): array
    {
        return [new ShortLinkExactKeyColumns($this->host->tablePrefix())];
    }

    /**
     * {@inheritdoc}
     */
    protected function createSchema(Connection $connection): void
    {
        $this->kernel = new TestKernel('exact_key_columns_gate', $this->host->bundleConfig(), TestKernel::defaultDatabaseUrl(), $this->host->configure());
        $this->kernel->boot();
        $this->host->resetSchema($this->kernel);
    }
}
