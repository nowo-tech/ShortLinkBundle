<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use LogicException;
use Nowo\ShortLinkBundle\Domain\Exception\InvalidShortLinkArgumentException;
use Nowo\ShortLinkBundle\Domain\Model\SourceErasureResult;
use Nowo\ShortLinkBundle\Domain\Model\SourceRefPage;
use Nowo\ShortLinkBundle\Domain\Model\SourceRetentionQuery;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkRetentionInterface;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

use function array_chunk;
use function array_flip;
use function array_slice;
use function count;
use function is_scalar;
use function preg_match;
use function sprintf;

/**
 * Source retention on the bundle tables, plain DBAL on the link connection (never an EntityManager). Each erased batch
 * is one unit through transaction-doctrine's DBAL runner: its links are locked in id order (`FOR UPDATE`; SQLite
 * serialises writers), marked deleted, their clicks and rollups deleted, then blanked like
 * {@see DbalEraser::eraseSource()}, and clicks deleted once more (a click recorded meanwhile). Rows are re-checked
 * exactly in PHP (rule LQ). Stateless (worker mode).
 *
 * @internal
 */
final readonly class DbalSourceRetention implements ShortLinkRetentionInterface
{
    /** Link ids per UPDATE / DELETE statement. */
    private const CHUNK = 500;

    /**
     * Holds the tables, the clock and the logger.
     */
    public function __construct(private ClickTables $tables, private ?ClockInterface $clock = null, private ?LoggerInterface $logger = null)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function sourcesCreatedBefore(SourceRetentionQuery $query, ?string $after = null): SourceRefPage
    {
        if ($after !== null && preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $after) !== 1) {
            throw new InvalidShortLinkArgumentException('A source cursor is a source ref of 1 to 64 characters [A-Za-z0-9._:-].');
        }
        $connection = $this->tables->linkConnection();
        $sql        = sprintf(
            'SELECT tenant_key, source_ref FROM %s WHERE tenant_key = ? AND kind = ? AND source_ref IS NOT NULL AND created_at < ?%s GROUP BY tenant_key, source_ref ORDER BY source_ref',
            $this->tables->links,
            $after === null ? '' : ' AND source_ref > ?',
        );
        $params = [$query->tenant->value, $query->kind->value, $this->utc($query->before)];
        if ($after !== null) {
            $params[] = $after;
        }
        $rows = $connection->fetchAllAssociative($connection->getDatabasePlatform()->modifyLimitQuery($sql, $query->batchSize + 1), $params);
        $refs = [];
        foreach (array_slice($rows, 0, $query->batchSize) as $row) {
            if ($this->text($row['tenant_key']) === $query->tenant->value) {
                $refs[] = $this->text($row['source_ref']);
            }
        }
        $last = count($rows) > $query->batchSize ? $this->text($rows[$query->batchSize - 1]['source_ref']) : null;

        return new SourceRefPage($refs, $last);
    }

    /**
     * {@inheritDoc}
     */
    public function eraseSourcesCreatedBefore(SourceRetentionQuery $query, ?int $maxBatches = null): SourceErasureResult
    {
        if ($maxBatches !== null && $maxBatches < 1) {
            throw new InvalidShortLinkArgumentException('At least one batch.');
        }
        $connection = $this->tables->linkConnection();
        if ($connection->isTransactionActive()) {
            throw new LogicException('Sources are erased in batches that commit on their own: call it outside an open transaction.');
        }
        $sources = 0;
        $rows    = 0;
        $batches = 0;
        while ($maxBatches === null || $batches < $maxBatches) {
            $page = $this->sourcesCreatedBefore($query);
            $refs = $page->sourceRefs;
            if ($refs === []) {
                return new SourceErasureResult($sources, $rows, $batches, true);
            }
            $touched = false;
            try {
                [$erased, $count] = DoctrineTransactionRunner::forConnection($connection, 1, $this->logger)
                    ->run(function () use ($connection, $query, $refs, &$touched): array {
                        return $this->eraseBatch($connection, $query, $refs, $touched);
                    });
            } catch (TransientStorageFailure $failure) {
                if ($batches === 0 && !$touched) {
                    throw $failure;
                }
                $kind = (new TransientFailureClassifier())->classify($failure) ?? TransientFailureKind::Retryable;

                throw new ConcurrencyConflict(1, $kind, $failure->retryAfterSeconds(), $failure, true);
            }
            if ($erased === 0) {
                // Listed but gone by the time the batch locked them: another run erased them. Stop rather than spin;
                // the empty batch wrote nothing, so it is not counted (0.7.1, audit YD2-I1).
                $this->logger?->info('nowo_short_link: the listed sources were erased by another run meanwhile.');

                return new SourceErasureResult($sources, $rows, $batches, false);
            }
            ++$batches;
            $sources += $erased;
            $rows += $count;
            if ($page->next === null) {
                return new SourceErasureResult($sources, $rows, $batches, true);
            }
        }

        return new SourceErasureResult($sources, $rows, $batches, false);
    }

    /**
     * One batch, inside the unit: every link of the listed sources, like {@see DbalEraser::eraseSource()} (a newer
     * link of an old source goes with it). `$touched` turns true once click rows on a separate connection may be deleted
     * (committed by that connection even when this unit rolls back).
     *
     * @param list<string> $refs
     *
     * @return array{int, int} sources erased, rows deleted or blanked
     */
    private function eraseBatch(Connection $connection, SourceRetentionQuery $query, array $refs, bool &$touched): array
    {
        $tenant = $query->tenant->value;
        $wanted = array_flip($refs);
        $lock   = $connection->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
        $found  = $connection->fetchAllAssociative(
            sprintf('SELECT id, tenant_key, source_ref FROM %s WHERE tenant_key = ? AND kind = ? AND source_ref IN (?) ORDER BY id%s', $this->tables->links, $lock),
            [$tenant, $query->kind->value, $refs],
            [2 => ArrayParameterType::STRING],
        );
        $ids     = [];
        $sources = [];
        foreach ($found as $row) {
            $ref = $this->text($row['source_ref']);
            if ($this->text($row['tenant_key']) === $tenant && isset($wanted[$ref])) {
                $ids[]         = $this->text($row['id']);
                $sources[$ref] = true;
            }
        }
        if ($ids === []) {
            return [0, 0];
        }
        $now   = $this->now();
        $count = 0;
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $connection->executeStatement(
                sprintf('UPDATE %s SET deleted_at = ?, source_slot = NULL WHERE tenant_key = ? AND id IN (?) AND deleted_at IS NULL', $this->tables->links),
                [$now, $tenant, $chunk],
                [2 => ArrayParameterType::STRING],
            );
        }
        $touched = !$this->tables->sharesConnection();
        $count += $this->deleteClicks($tenant, $ids);
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $count += (int) $connection->executeStatement(
                sprintf('UPDATE %s SET %s WHERE tenant_key = ? AND id IN (?)', $this->tables->links, DbalEraser::BLANK),
                [false, $now, $tenant, $chunk],
                [0 => Types::BOOLEAN, 3 => ArrayParameterType::STRING],
            );
        }

        return [count($sources), $count + $this->deleteClicks($tenant, $ids)];
    }

    /**
     * Deletes the click and rollup rows of some links of a tenant.
     *
     * @param list<string> $ids
     */
    private function deleteClicks(string $tenant, array $ids): int
    {
        $clicks = $this->tables->clickConnection();
        $count  = 0;
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach ([$this->tables->clicks, $this->tables->daily] as $table) {
                $count += (int) $clicks->executeStatement(sprintf('DELETE FROM %s WHERE tenant_key = ? AND link_id IN (?)', $table), [$tenant, $chunk], [1 => ArrayParameterType::STRING]);
            }
        }

        return $count;
    }

    private function now(): string
    {
        return $this->utc($this->clock?->now() ?? new DateTimeImmutable());
    }

    private function utc(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
