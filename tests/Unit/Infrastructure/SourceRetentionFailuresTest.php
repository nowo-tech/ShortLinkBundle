<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\SourceRetentionQuery;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\ClickTables;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\DbalSourceRetention;
use Nowo\ShortLinkBundle\Tests\Fixtures\MemoryLogger;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PHPUnit\Framework\TestCase;

use function array_shift;
use function in_array;
use function str_contains;

/**
 * Conflict codes and the lost-race stop of the batched source erasure, on scripted connections (the real lock races
 * run in the database suite on MySQL, MariaDB and PostgreSQL).
 */
final class SourceRetentionFailuresTest extends TestCase
{
    private const TENANT = 'org-1';

    public function testSourcesErasedByAnotherRunMeanwhileStopTheCall(): void
    {
        $links  = $this->connection([[['tenant_key' => self::TENANT, 'source_ref' => 's1']], []], []);
        $logger = new MemoryLogger();
        $result = (new DbalSourceRetention($this->tables($links), null, $logger))->eraseSourcesCreatedBefore($this->query(2));

        self::assertSame([0, 0, 1, false], [$result->sources, $result->rows, $result->batches, $result->complete]);
        self::assertCount(1, $logger->records);
    }

    public function testAConflictInTheFirstBatchIsRetrySafe(): void
    {
        $links = $this->connection([$this->listing(), $this->batch()], [0]);

        try {
            (new DbalSourceRetention($this->tables($links)))->eraseSourcesCreatedBefore($this->query(2));
            self::fail('Lock conflict.');
        } catch (TransientStorageFailure $failure) {
            self::assertNotInstanceOf(ConcurrencyConflict::class, $failure);
            self::assertSame(ConflictCode::RetrySafe, ConflictCode::forFailure($failure));
        }
    }

    public function testAConflictAfterACommittedBatchSaysPartIsCommitted(): void
    {
        $two   = [['tenant_key' => self::TENANT, 'source_ref' => 's1'], ['tenant_key' => self::TENANT, 'source_ref' => 's2']];
        $links = $this->connection([$two, $this->batch(), $two, $this->batch()], [6]);

        try {
            (new DbalSourceRetention($this->tables($links)))->eraseSourcesCreatedBefore($this->query(1));
            self::fail('Lock conflict.');
        } catch (ConcurrencyConflict $conflict) {
            self::assertTrue($conflict->committed);
            self::assertSame(TransientFailureKind::Retryable, $conflict->kind);
            self::assertSame(3, $conflict->retryAfterSeconds());
            self::assertSame(ConflictCode::Committed, ConflictCode::forFailure($conflict));
        }
    }

    public function testClicksDeletedOnASeparateConnectionCountAsCommitted(): void
    {
        $links  = $this->connection([$this->listing(), $this->batch()], [1]);
        $clicks = $this->connection([], []);

        try {
            (new DbalSourceRetention($this->tables($links, $clicks)))->eraseSourcesCreatedBefore($this->query(2));
            self::fail('Lock conflict.');
        } catch (ConcurrencyConflict $conflict) {
            self::assertTrue($conflict->committed, 'the click rows were deleted by their own connection before the conflict');
        }
    }

    private function query(int $batchSize): SourceRetentionQuery
    {
        return new SourceRetentionQuery(TenantKey::fromString(self::TENANT), LinkKind::email(), new DateTimeImmutable('2026-08-01'), $batchSize);
    }

    /**
     * @return list<array<string, string>>
     */
    private function listing(): array
    {
        return [['tenant_key' => self::TENANT, 'source_ref' => 's1']];
    }

    /**
     * @return list<array<string, string>>
     */
    private function batch(): array
    {
        return [['id' => '0190a6d0-0000-7000-8000-000000000001', 'tenant_key' => self::TENANT, 'source_ref' => 's1']];
    }

    /**
     * A connection that answers the reads in order and throws a lock conflict on the given write calls (0-based).
     *
     * @param list<list<array<string, string>>> $reads
     * @param list<int> $failingWrites
     */
    private function connection(array $reads, array $failingWrites): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->method('fetchAllAssociative')->willReturnCallback(static function () use (&$reads): array {
            return array_shift($reads) ?? [];
        });
        $writes = 0;
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$writes, $failingWrites): int {
            if (in_array($writes++, $failingWrites, true)) {
                throw new TransientStorageFailure(3);
            }

            return str_contains($sql, 'DELETE') ? 0 : 1;
        });

        return $connection;
    }

    private function tables(Connection $links, ?Connection $clicks = null): ClickTables
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($links);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);
        $registry->method('getConnection')->willReturn($clicks ?? $links);

        return new ClickTables($registry, $clicks instanceof Connection ? 'clicks' : null);
    }
}
