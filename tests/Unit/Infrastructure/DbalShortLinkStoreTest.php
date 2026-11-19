<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\DbalShortLinkStore;
use Nowo\ShortLinkBundle\Tests\Fixtures\MemoryLogger;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\TestCase;
use stdClass;

use function str_ends_with;
use function str_starts_with;

/**
 * {@see DbalShortLinkStore} failure paths: the reconnect-once rule (reads, and write units lost before their work
 * finished), the tenant database lock per platform, the row lock of read-modify-write paths and a manager that is not
 * an ORM entity manager.
 */
final class DbalShortLinkStoreTest extends TestCase
{
    private const ID = '0190a6d0-0000-7000-8000-000000000001';

    public function testALostConnectionIsRetriedOnceOutsideATransaction(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->expects(self::once())->method('close');
        $calls = 0;
        $connection->method('fetchAllAssociative')->willReturnCallback(static function () use (&$calls): array {
            if (++$calls === 1) {
                throw self::lost();
            }

            return [];
        });
        $logger = new MemoryLogger();

        self::assertNull($this->store($this->registry($connection), $logger)->find(TenantKey::default(), ShortLinkId::fromString(self::ID)));
        self::assertSame(2, $calls);
        self::assertCount(1, $logger->records);
    }

    public function testALostConnectionInsideATransactionIsNotRetried(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::never())->method('close');
        $connection->method('fetchAllAssociative')->willThrowException(self::lost());

        $this->expectException(ConnectionLost::class);
        $this->store($this->registry($connection))->find(TenantKey::default(), ShortLinkId::fromString(self::ID));
    }

    public function testAWriteUnitLostBeforeItsWorkFinishedIsReplayedOnceOnANewConnection(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->expects(self::once())->method('close');
        $runs = 0;

        $result = $this->store($this->registry($connection), new MemoryLogger())->transactional(static function () use (&$runs): string {
            if (++$runs === 1) {
                throw self::lost();
            }

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(2, $runs, 'nothing was committed: the unit runs once more');
    }

    public function testAWriteUnitLostDuringCommitIsNeverReplayed(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->method('commit')->willThrowException(self::lost());
        $connection->expects(self::never())->method('close');
        $runs = 0;

        try {
            $this->store($this->registry($connection))->transactional(static function () use (&$runs): void {
                ++$runs;
            });
            self::fail('a commit of unknown outcome is rethrown');
        } catch (ConnectionLost) {
            self::assertSame(1, $runs, 'replaying could duplicate the committed rows');
        }
    }

    public function testAWriteUnitLostInsideAHostTransactionIsNotReplayed(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->expects(self::never())->method('close');

        $this->expectException(ConnectionLost::class);
        $this->store($this->registry($connection))->transactional(static function (): never {
            throw self::lost();
        });
    }

    public function testALockConflictOfAWriteUnitIsRethrownAsATransientFailure(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $deadlock = new DeadlockException(new class('Deadlock found', '40001', 1213) extends AbstractException {
        }, null);

        $this->expectExceptionObject(TransientStorageFailure::fromPrevious($deadlock, 1));
        $this->store($this->registry($connection))->transactional(static function () use ($deadlock): never {
            throw $deadlock;
        });
    }

    public function testMySqlTenantLockIsTakenInsideTheUnitAndReleasedAfterIt(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $sql = [];
        $connection->method('fetchOne')->willReturnCallback(static function (string $query, array $params) use (&$sql): string {
            $sql[] = [$query, $params];

            return '1';
        });

        $result = $this->store($this->registry($connection))->transactional(static fn (): string => 'ok', null, TenantKey::fromString('acme'));

        self::assertSame('ok', $result);
        self::assertCount(2, $sql);
        self::assertSame('SELECT GET_LOCK(?, ?)', $sql[0][0]);
        self::assertSame('SELECT RELEASE_LOCK(?)', $sql[1][0]);
        self::assertSame($sql[0][1][0], $sql[1][1][0], 'the same lock name');
        self::assertIsString($sql[0][1][0]);
        self::assertMatchesRegularExpression('/^nowo_short_link_q_[0-9a-f]{40}$/', $sql[0][1][0]);
        self::assertSame(DbalShortLinkStore::TENANT_LOCK_TIMEOUT, $sql[0][1][1]);
    }

    public function testMySqlTenantLockNotObtainedInTimeIsATransientFailureAndNothingRuns(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls('0', '0');
        $ran = false;

        try {
            $this->store($this->registry($connection))->transactional(static function () use (&$ran): void {
                $ran = true;
            }, null, TenantKey::default());
            self::fail('expected a transient failure');
        } catch (TransientStorageFailure $failure) {
            self::assertSame(DbalShortLinkStore::TENANT_LOCK_MESSAGE, $failure->getMessage());
            self::assertSame(1, $failure->retryAfterSeconds());
        }
        self::assertFalse($ran);
    }

    public function testAFailedReleaseClosesTheConnectionSoNoWorkerKeepsTheLock(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $connection->method('fetchOne')->willReturnCallback(static function (string $query): string {
            if ($query === 'SELECT RELEASE_LOCK(?)') {
                throw self::lost();
            }

            return '1';
        });
        $connection->expects(self::once())->method('close');
        $logger = new MemoryLogger();

        self::assertSame(1, $this->store($this->registry($connection), $logger)->transactional(static fn (): int => 1, null, TenantKey::default()));
        self::assertCount(1, $logger->records);
    }

    /**
     * Audit UK-SL-3: inside an open host transaction a failed release never closes the connection (the host's work
     * would be lost); the release is retried when the outermost transaction ends, and closes only if it fails again.
     */
    public function testAFailedReleaseInsideAHostTransactionIsDeferredToTheEndOfIt(): void
    {
        foreach ([true, false] as $commits) {
            $native     = new stdClass();
            $connection = $this->createMock(Connection::class);
            $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
            $connection->method('getNativeConnection')->willReturn($native);
            $active = true;
            $connection->method('isTransactionActive')->willReturnCallback(static function () use (&$active): bool {
                return $active;
            });
            $releases = new class {
                public int $count = 0;
            };
            $connection->method('fetchOne')->willReturnCallback(static function (string $query) use ($releases): string {
                if ($query === 'SELECT RELEASE_LOCK(?)' && ++$releases->count === 1) {
                    throw self::lost();
                }

                return '1';
            });
            $connection->expects(self::never())->method('close');
            $queue  = new AfterCommitQueue();
            $logger = new MemoryLogger();

            self::assertSame(1, (new DbalShortLinkStore($this->registry($connection), $queue, null, '', $logger))->transactional(static fn (): int => 1, null, TenantKey::default()));
            self::assertSame(1, $releases->count);
            self::assertCount(1, $logger->records);

            $active = false;
            $commits ? $queue->committed($native) : $queue->rolledBack($native);
            self::assertSame(2, $releases->count, 'released once the host transaction ended');
            $queue->committed($native);
            self::assertSame(2, $releases->count, 'only once');
        }
    }

    public function testADeferredReleaseThatFailsAgainClosesTheConnectionAndABoundaryRunsIt(): void
    {
        $native     = new stdClass();
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $connection->method('getNativeConnection')->willReturn($native);
        $active = true;
        $connection->method('isTransactionActive')->willReturnCallback(static function () use (&$active): bool {
            return $active;
        });
        $connection->method('fetchOne')->willReturnCallback(static function (string $query): string {
            if ($query === 'SELECT RELEASE_LOCK(?)') {
                throw self::lost();
            }

            return '1';
        });
        $connection->expects(self::once())->method('close');
        $queue  = new AfterCommitQueue();
        $logger = new MemoryLogger();

        (new DbalShortLinkStore($this->registry($connection), $queue, null, '', $logger))->transactional(static fn (): int => 1, null, TenantKey::default());
        $active = false;
        $queue->reset();
        self::assertCount(2, $logger->records, 'deferred, then closed at the request boundary');
        $queue->reset();
    }

    public function testPostgreSqlTenantLockIsATransactionAdvisoryLockUnderATransactionLocalLockTimeout(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $calls = [];
        $connection->method('fetchOne')->willReturnCallback(static function (string $query, array $params = []) use (&$calls): string {
            $calls[] = [$query, $params];

            return '5s';
        });

        self::assertSame('ok', (new DbalShortLinkStore($this->registry($connection), new AfterCommitQueue(), null, '', null, 3))->transactional(static fn (): string => 'ok', null, TenantKey::default()));
        self::assertSame("SELECT current_setting('lock_timeout')", $calls[0][0]);
        self::assertSame(["SELECT set_config('lock_timeout', ?, true)", ['3000ms']], $calls[1]);
        self::assertSame('SELECT pg_advisory_xact_lock(CAST(? AS BIGINT))', $calls[2][0]);
        self::assertIsInt($calls[2][1][0]);
        self::assertSame(["SELECT set_config('lock_timeout', ?, true)", ['5s']], $calls[3], 'the previous value is restored; never released by hand');
        self::assertCount(4, $calls);
    }

    public function testAPostgreSqlLockTimeoutIsATransientFailure(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $timeout = new LockWaitTimeoutException(new class('canceling statement due to lock timeout', '55P03') extends AbstractException {
        }, null);
        $connection->method('fetchOne')->willReturnCallback(static function (string $query) use ($timeout): string {
            if (str_starts_with($query, 'SELECT pg_advisory_xact_lock')) {
                throw $timeout;
            }

            return '0';
        });
        $ran = false;

        try {
            $this->store($this->registry($connection))->transactional(static function () use (&$ran): void {
                $ran = true;
            }, null, TenantKey::default());
            self::fail('expected a transient failure');
        } catch (TransientStorageFailure $failure) {
            self::assertSame($timeout, $failure->getPrevious());
        }
        self::assertFalse($ran);
    }

    public function testSqliteTakesNoTenantLock(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->expects(self::never())->method('fetchOne');

        self::assertSame('ok', $this->store($this->registry($connection))->transactional(static fn (): string => 'ok', null, TenantKey::default()));
    }

    public function testFindForUpdateLocksTheRowOnServerDatabasesAndComparesExactly(): void
    {
        foreach ([[new MySQL80Platform(), true], [new PostgreSQLPlatform(), true], [new SQLitePlatform(), false]] as [$platform, $locks]) {
            $connection = $this->createMock(Connection::class);
            $connection->method('getDatabasePlatform')->willReturn($platform);
            $queries = [];
            $connection->method('fetchAllAssociative')->willReturnCallback(static function (string $query) use (&$queries): array {
                $queries[] = $query;

                return [self::row('ACME'), self::row('acme')];
            });
            $found = $this->store($this->registry($connection))->findForUpdate(TenantKey::fromString('acme'), ShortLinkId::fromString(self::ID));

            self::assertNotNull($found);
            self::assertSame('acme', $found->tenant->value, 'a look-alike tenant row is never returned');
            self::assertSame($locks, str_ends_with($queries[0], ' FOR UPDATE'));
        }

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->method('fetchAllAssociative')->willReturn([self::row('other')]);
        self::assertNull($this->store($this->registry($connection))->findForUpdate(TenantKey::fromString('acme'), ShortLinkId::fromString(self::ID)));
    }

    public function testTheManagerMustBeAnOrmEntityManager(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createStub(ObjectManager::class));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Doctrine ORM entity manager');
        $this->store($registry)->codeExists('abc1234');
    }

    private function store(ManagerRegistry $registry, ?MemoryLogger $logger = null): DbalShortLinkStore
    {
        return new DbalShortLinkStore($registry, new AfterCommitQueue(), null, '', $logger);
    }

    private function registry(Connection $connection): ManagerRegistry
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);

        return $registry;
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(string $tenant): array
    {
        return [
            'id'              => self::ID,
            'tenant_key'      => $tenant,
            'code'            => 'abc1234',
            'code_custom'     => 0,
            'target_url'      => 'https://example.com/',
            'target_host'     => 'example.com',
            'title'           => null,
            'kind'            => 'manual',
            'source_ref'      => null,
            'source_label'    => null,
            'source_position' => null,
            'metadata'        => null,
            'active'          => 1,
            'expires_at'      => null,
            'disabled_reason' => null,
            'created_at'      => '2026-11-12 10:00:00',
            'updated_at'      => '2026-11-12 10:00:00',
            'deleted_at'      => null,
            'created_by'      => null,
            'updated_by'      => null,
        ];
    }

    private static function lost(): ConnectionLost
    {
        return new ConnectionLost(new class('server has gone away') extends AbstractException {
        }, null);
    }
}
