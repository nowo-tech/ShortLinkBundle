<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\TestKernel;
use Symfony\Component\Filesystem\Filesystem;

use function getenv;
use function is_string;

/**
 * The CI database matrix (SQLite, MySQL, MariaDB, PostgreSQL; `NOWO_TEST_DATABASE_URL`) boots the bundle next to
 * DoctrineBundle and proves the rule the `code` column follows from S1 (contract F3, audit LQ): random base62 codes are
 * case-sensitive, so `aBcdEf1` and `ABCDEF1` are two distinct unique keys and a lookup never answers the other one
 * (`utf8mb4_bin` on MySQL / MariaDB, the default elsewhere).
 */
final class DatabaseBootTest extends KernelTestCase
{
    public function testShortCodesAreUniqueAndCompareExactlyOnTheConfiguredDatabase(): void
    {
        $connection = $this->connection();
        $collation  = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin' : '';

        $connection->executeStatement('DROP TABLE IF EXISTS s0_code_probe');
        $connection->executeStatement('CREATE TABLE s0_code_probe (code VARCHAR(32)' . $collation . ' NOT NULL, target VARCHAR(64) NOT NULL)');
        $connection->executeStatement('CREATE UNIQUE INDEX uniq_s0_code_probe ON s0_code_probe (code)');
        $connection->insert('s0_code_probe', ['code' => 'aBcdEf1', 'target' => 'first']);
        $connection->insert('s0_code_probe', ['code' => 'ABCDEF1', 'target' => 'second']);

        self::assertSame('first', $connection->fetchOne('SELECT target FROM s0_code_probe WHERE code = ?', ['aBcdEf1']));
        self::assertSame('second', $connection->fetchOne('SELECT target FROM s0_code_probe WHERE code = ?', ['ABCDEF1']));
        self::assertFalse($connection->fetchOne('SELECT target FROM s0_code_probe WHERE code = ?', ['abcdef1']));

        $connection->executeStatement('DROP TABLE s0_code_probe');
    }

    private function connection(): Connection
    {
        $url = getenv('NOWO_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            (new Filesystem())->mkdir(TestKernel::baseDir());
            $url = 'sqlite:///' . TestKernel::baseDir() . '/probe.sqlite';
        }
        $connection = $this->bootKernel([], $url)->getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
