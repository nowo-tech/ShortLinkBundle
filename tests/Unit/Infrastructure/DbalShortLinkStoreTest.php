<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\ShortLinkBundle\Application\HostTransactionLostException;
use Nowo\ShortLinkBundle\Application\Store\StoredLink;
use Nowo\ShortLinkBundle\Domain\Exception\CodeTakenException;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\LinkSource;
use Nowo\ShortLinkBundle\Domain\Model\ShortCode;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\DbalShortLinkStore;
use Nowo\ShortLinkBundle\Tests\Fixtures\MemoryLogger;
use Nowo\ShortLinkBundle\Tests\Fixtures\NestingState;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

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

    /**
     * Audit UY-1: a joined unit whose failure left DBAL below the host's level (MySQL / MariaDB deadlock victim: the
     * server rolled the host transaction back, ROLLBACK TO SAVEPOINT failed and the runner closed the connection). The
     * host's levels are opened again and marked rollback-only, so its later statements can never autocommit, and the
     * lock conflict becomes HostTransactionLostException.
     */
    public function testAJoinedUnitThatLostTheHostTransactionLeavesItRollbackOnlyAndSaysSo(): void
    {
        $state      = new NestingState(2);
        $connection = $this->nestingConnection(new MySQL80Platform(), $state);
        $connection->expects(self::once())->method('setRollbackOnly');
        $deadlock = new DeadlockException(new class('Deadlock found', '40001', 1213) extends AbstractException {
        }, null);
        $logger = new MemoryLogger();

        $caught = null;
        try {
            $this->store($this->registry($connection), $logger)->transactional(static function () use ($state, $deadlock): never {
                $state->level = 0;

                throw $deadlock;
            });
        } catch (HostTransactionLostException $exception) {
            $caught = $exception;
        }
        self::assertSame(HostTransactionLostException::MESSAGE, $caught->getMessage());
        self::assertSame(1, $caught->retryAfterSeconds());
        $previous = $caught->getPrevious();
        self::assertInstanceOf(TransientStorageFailure::class, $previous);
        self::assertSame($deadlock, $previous->getPrevious());
        self::assertSame(2, $state->level, 'back at the host\'s level');
        self::assertSame(['warning'], $this->bundleLogLevels($logger));
    }

    /**
     * A lost connection that took the host transaction is not a conflict: rethrown as it is, but the host's level is
     * still reopened rollback-only. When even that fails (the server is gone) it is logged and the failure rethrown.
     */
    public function testALostHostTransactionThatIsNoConflictIsRethrownAndAFailedReopenIsLogged(): void
    {
        foreach ([false, true] as $reopenFails) {
            $state      = new NestingState(1, $reopenFails);
            $connection = $this->nestingConnection(new SQLitePlatform(), $state);
            $connection->expects($reopenFails ? self::never() : self::once())->method('setRollbackOnly');
            $lost   = self::lost();
            $logger = new MemoryLogger();

            $caught = null;
            try {
                $this->store($this->registry($connection), $logger)->transactional(static function () use ($state, $lost): never {
                    $state->level = 0;

                    throw $lost;
                });
            } catch (ConnectionLost $exception) {
                $caught = $exception;
            }
            self::assertSame($lost, $caught);
            self::assertSame($reopenFails ? ['warning', 'error'] : ['warning'], $this->bundleLogLevels($logger));
            self::assertSame($reopenFails ? 0 : 1, $state->level);
        }
    }

    /**
     * Audit UY2-3: the reopen failed after DBAL already raised the level (BEGIN or the savepoint SQL failed): the open
     * level is still marked rollback-only.
     */
    public function testAReopenThatFailsAfterRaisingTheLevelStillMarksItRollbackOnly(): void
    {
        $state      = new NestingState(2, true, true);
        $connection = $this->nestingConnection(new MySQL80Platform(), $state);
        $connection->expects(self::once())->method('setRollbackOnly');
        $deadlock = new DeadlockException(new class('Deadlock found', '40001', 1213) extends AbstractException {
        }, null);
        $logger = new MemoryLogger();

        $caught = null;
        try {
            $this->store($this->registry($connection), $logger)->transactional(static function () use ($state, $deadlock): never {
                $state->level = 0;

                throw $deadlock;
            });
        } catch (HostTransactionLostException $exception) {
            $caught = $exception;
        }
        self::assertSame(HostTransactionLostException::MESSAGE, $caught->getMessage());
        self::assertSame(1, $state->level);
        self::assertSame(['warning', 'error'], $this->bundleLogLevels($logger));
    }

    /**
     * Audit UY2-4: a unit never joins a host transaction that is already rollback-only; nothing runs.
     */
    public function testAUnitNeverJoinsARollbackOnlyTransaction(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('isRollbackOnly')->willReturn(true);
        $connection->expects(self::never())->method('beginTransaction');
        $ran = false;

        try {
            $this->store($this->registry($connection))->transactional(static function () use (&$ran): void {
                $ran = true;
            });
            self::fail('expected HostTransactionLostException');
        } catch (HostTransactionLostException $exception) {
            self::assertNull($exception->getPrevious());
        }
        self::assertFalse($ran);
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
     * Audit UK-SL-2: the tenant lock is refused inside an open transaction (the MySQL / MariaDB session lock would end
     * before the host commits, and a failed release could only close the host's connection); nothing runs.
     */
    public function testTheTenantLockIsRefusedInsideAnOpenTransaction(): void
    {
        foreach ([new MySQL80Platform(), new PostgreSQLPlatform()] as $platform) {
            $connection = $this->createMock(Connection::class);
            $connection->method('getDatabasePlatform')->willReturn($platform);
            $connection->method('isTransactionActive')->willReturn(true);
            $connection->expects(self::never())->method('fetchOne');
            $connection->expects(self::never())->method('close');
            $ran = false;

            try {
                $this->store($this->registry($connection))->transactional(static function () use (&$ran): void {
                    $ran = true;
                }, null, TenantKey::default());
                self::fail('expected a LogicException');
            } catch (LogicException $exception) {
                self::assertStringContainsString('outside an open transaction', $exception->getMessage());
            }
            self::assertFalse($ran);
        }
    }

    /**
     * Audit UK-SL-2: inside a transaction a generated link is inserted in a savepoint; the live-source unique violation
     * rolls back to it (the transaction survives, PostgreSQL included) and answers the link that won the race, read
     * with a shared lock on MySQL / MariaDB (latest committed row, no lock upgrade) and plainly elsewhere.
     */
    public function testASourceRaceInsideATransactionRollsBackToItsSavepointAndAnswersTheWinner(): void
    {
        foreach ([[new MySQL80Platform(), ' LOCK IN SHARE MODE'], [new PostgreSQLPlatform(), ''], [new SQLitePlatform(), '']] as [$platform, $lock]) {
            $connection = $this->createMock(Connection::class);
            $connection->method('getDatabasePlatform')->willReturn($platform);
            $connection->method('isTransactionActive')->willReturn(true);
            $connection->method('insert')->willThrowException($this->duplicate());
            $calls = [];
            $connection->method('createSavepoint')->willReturnCallback(static function (string $name) use (&$calls): void {
                $calls[] = 'create ' . $name;
            });
            $connection->method('rollbackSavepoint')->willReturnCallback(static function (string $name) use (&$calls): void {
                $calls[] = 'rollback ' . $name;
            });
            $connection->expects(self::never())->method('releaseSavepoint');
            $queries = [];
            $connection->method('fetchAllAssociative')->willReturnCallback(static function (string $query, array $params) use (&$queries): array {
                $queries[] = [$query, $params];

                return [self::row('ACME', 'send-1', 2), self::row('acme', 'send-1', 3), self::row('acme', 'send-1', 2, 'winner1')];
            });
            $mine = $this->link('acme', 'send-1', 2);

            $stored = $this->store($this->registry($connection))->insert($mine);

            self::assertNotSame($mine, $stored);
            self::assertSame('winner1', $stored->code->value, 'the look-alike tenant and the other position are never returned');
            self::assertSame(['create nowo_short_link_source', 'rollback nowo_short_link_source'], $calls);
            self::assertCount(1, $queries);
            self::assertStringEndsWith('source_slot = ? AND deleted_at IS NULL' . $lock, $queries[0][0]);
            self::assertSame(['acme', 'email', 'send-1', 2], $queries[0][1]);
        }
    }

    public function testASourceWithoutAPositionUsesTheNoPositionSlotAndASuccessfulInsertReleasesTheSavepoint(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->method('isTransactionActive')->willReturn(true);
        $rows = [];
        $connection->method('insert')->willReturnCallback(static function (string $table, array $row) use (&$rows): int {
            $rows[] = $row;

            return 1;
        });
        $connection->expects(self::once())->method('createSavepoint');
        $connection->expects(self::once())->method('releaseSavepoint')->with('nowo_short_link_source');
        $connection->expects(self::never())->method('rollbackSavepoint');
        $mine = $this->link('acme', 'send-1', null);

        self::assertSame($mine, $this->store($this->registry($connection))->insert($mine));
        self::assertSame(-1, $rows[0]['source_slot']);
        self::assertNull($rows[0]['source_position']);
    }

    /**
     * Outside a transaction (autocommit) no savepoint is needed; a unique violation with no live link of the source is
     * the code index: CodeTakenException, as before. An unsourced or deleted row never gets a slot.
     */
    public function testOutsideATransactionNoSavepointAndAViolationWithoutAWinnerIsTheCode(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->method('insert')->willThrowException($this->duplicate());
        $connection->method('fetchAllAssociative')->willReturn([]);
        $connection->expects(self::never())->method('createSavepoint');
        $connection->expects(self::never())->method('rollbackSavepoint');

        try {
            $this->store($this->registry($connection))->insert($this->link('acme', 'send-1', 0));
            self::fail('expected CodeTakenException');
        } catch (CodeTakenException $exception) {
            self::assertInstanceOf(UniqueConstraintViolationException::class, $exception->getPrevious());
        }

        $plain = $this->createMock(Connection::class);
        $plain->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $plain->method('isTransactionActive')->willReturn(true);
        $rows = [];
        $plain->method('insert')->willReturnCallback(static function (string $table, array $row) use (&$rows): int {
            $rows[] = $row;

            return 1;
        });
        $plain->expects(self::never())->method('createSavepoint');
        $store = $this->store($this->registry($plain));
        $store->insert($this->link('acme', null, null));
        $deleted = $this->link('acme', 'send-1', 4);
        $store->insert(new StoredLink($deleted->id, $deleted->tenant, $deleted->code, $deleted->target, null, $deleted->kind, $deleted->source, null, true, null, null, $deleted->createdAt, $deleted->updatedAt, new DateTimeImmutable('2026-11-12 11:00:00'), null, null));
        self::assertNull($rows[0]['source_slot']);
        self::assertNull($rows[1]['source_slot'], 'a tombstone never holds the source');
        self::assertSame(4, $rows[1]['source_position']);
    }

    /**
     * An edit never claims the live-source slot (a duplicate left without one by the 0.6.0 backfill stays editable); a
     * tombstone clears it.
     */
    public function testAnEditLeavesTheSourceSlotAndATombstoneClearsIt(): void
    {
        $connection = $this->createMock(Connection::class);
        $rows       = [];
        $connection->method('update')->willReturnCallback(static function (string $table, array $row) use (&$rows): int {
            $rows[] = $row;

            return 1;
        });
        $store = $this->store($this->registry($connection));
        $live  = $this->link('acme', 'send-1', 0);
        $store->update($live);
        $store->update(new StoredLink($live->id, $live->tenant, $live->code, $live->target, null, $live->kind, $live->source, null, true, null, null, $live->createdAt, $live->updatedAt, new DateTimeImmutable('2026-11-12 11:00:00'), null, null));

        self::assertArrayNotHasKey('source_slot', $rows[0]);
        self::assertArrayHasKey('source_slot', $rows[1]);
        self::assertNull($rows[1]['source_slot']);
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

    /**
     * A connection whose nesting level follows the state: beginTransaction() opens a level (or fails at level 0 when
     * the state says the server is gone).
     *
     * @return Connection&MockObject
     */
    private function nestingConnection(AbstractPlatform $platform, NestingState $state): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('isTransactionActive')->willReturnCallback(static fn (): bool => $state->level > 0);
        $connection->method('getTransactionNestingLevel')->willReturnCallback(static fn (): int => $state->level);
        $connection->method('beginTransaction')->willReturnCallback(static function () use ($state): void {
            if ($state->reopenFails && $state->level === 0) {
                if ($state->raiseThenFail) {
                    ++$state->level;
                }

                throw self::lost();
            }
            ++$state->level;
        });

        return $connection;
    }

    /**
     * Levels of the bundle's own log records (the runner logs the failure too).
     *
     * @return list<mixed>
     */
    private function bundleLogLevels(MemoryLogger $logger): array
    {
        $levels = [];
        foreach ($logger->records as $record) {
            if (str_starts_with($record['message'], 'nowo_short_link:')) {
                $levels[] = $record['level'];
            }
        }

        return $levels;
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
    private static function row(string $tenant, ?string $sourceRef = null, ?int $position = null, string $code = 'abc1234'): array
    {
        return [
            'id'              => self::ID,
            'tenant_key'      => $tenant,
            'code'            => $code,
            'code_custom'     => 0,
            'target_url'      => 'https://example.com/',
            'target_host'     => 'example.com',
            'title'           => null,
            'kind'            => $sourceRef === null ? 'manual' : 'email',
            'source_ref'      => $sourceRef,
            'source_label'    => null,
            'source_position' => $position,
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

    private function link(string $tenant, ?string $sourceRef, ?int $position): StoredLink
    {
        $now = new DateTimeImmutable('2026-11-12 10:00:00');

        return new StoredLink(
            ShortLinkId::fromString(self::ID),
            TenantKey::fromString($tenant),
            ShortCode::random('mine123'),
            TargetUrl::fromString('https://example.com/'),
            null,
            $sourceRef === null ? LinkKind::manual() : LinkKind::email(),
            $sourceRef === null ? null : new LinkSource($sourceRef, null, $position),
            null,
            true,
            null,
            null,
            $now,
            $now,
            null,
            null,
            null,
        );
    }

    private function duplicate(): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException(new class('Duplicate entry', '23000', 1062) extends AbstractException {
        }, null);
    }

    private static function lost(): ConnectionLost
    {
        return new ConnectionLost(new class('server has gone away') extends AbstractException {
        }, null);
    }
}
