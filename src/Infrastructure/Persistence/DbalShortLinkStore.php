<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Persistence;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\ShortLinkBundle\Application\Store\ActiveBatch;
use Nowo\ShortLinkBundle\Application\Store\ShortLinkStoreInterface;
use Nowo\ShortLinkBundle\Application\Store\StoredLink;
use Nowo\ShortLinkBundle\Domain\Exception\CodeTakenException;
use Nowo\ShortLinkBundle\Domain\Model\ActorRef;
use Nowo\ShortLinkBundle\Domain\Model\Expiry;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\LinkMetadata;
use Nowo\ShortLinkBundle\Domain\Model\LinkSource;
use Nowo\ShortLinkBundle\Domain\Model\LinkTitle;
use Nowo\ShortLinkBundle\Domain\Model\ResolvedLink;
use Nowo\ShortLinkBundle\Domain\Model\ShortCode;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkListQuery;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkState;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Infrastructure\Doctrine\ShortLinkMetadataListener;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_map;
use function count;
use function hash;
use function hexdec;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_object;
use function is_scalar;
use function json_decode;
use function max;
use function mb_strtolower;
use function sprintf;
use function str_replace;
use function substr;

/**
 * DBAL store of the links (contract §5.1): plain SQL on the entity manager's connection, never the ORM unit of work,
 * so the host's EntityManager is never flushed (rule 6). Writes run through transaction-doctrine's DBAL runner
 * (REQ-RUNTIME-003 rules 1 and 3: one transaction per write unit, a failed nested unit rolls back to its savepoint, a
 * lock conflict is rethrown as a TransientStorageFailure), join an open host transaction and hold their after-commit
 * work until the outermost commit ({@see AfterCommitQueue}). Every tenant-scoped read filters on the tenant and
 * re-checks tenant, id and code with `===` in PHP (rule LQ). Stateless (worker mode); a lost connection is reopened
 * once outside a transaction, and a write unit is replayed on it only when the connection was lost before the work
 * finished (a connection lost during COMMIT has an unknown outcome and is rethrown, never replayed).
 *
 * @internal
 */
final readonly class DbalShortLinkStore implements ShortLinkStoreInterface
{
    /** Seconds a write waits for the tenant's database lock before answering a lock conflict (rule 9). */
    public const TENANT_LOCK_TIMEOUT = 10;

    /** Message of the transient failure thrown when the tenant's database lock is not obtained in time. */
    public const TENANT_LOCK_MESSAGE = 'Another link of this tenant is being created at the same time. Retry in a moment.';

    /** Prefix of the MySQL / MariaDB user-level lock names (+ 40 hex characters: 58 of the 64 allowed). */
    private const LOCK_NAME_PREFIX = 'nowo_short_link_q_';

    private const COLUMNS = 'id, tenant_key, code, code_custom, target_url, target_host, title, kind, source_ref, source_label, source_position, metadata, active, expires_at, disabled_reason, created_at, updated_at, deleted_at, created_by, updated_by';

    private string $table;

    /**
     * @param string|null $entityManager `doctrine.entity_manager`; null = the default entity manager
     * @param string $tablePrefix `doctrine.table_prefix`
     */
    public function __construct(
        private ManagerRegistry $registry,
        private AfterCommitQueue $afterCommit,
        private ?string $entityManager = null,
        string $tablePrefix = '',
        private ?LoggerInterface $logger = null,
        private int $tenantLockTimeout = self::TENANT_LOCK_TIMEOUT,
    ) {
        $this->table = $tablePrefix . ShortLinkMetadataListener::TABLE;
    }

    /**
     * {@inheritDoc}
     */
    public function transactional(Closure $work, ?Closure $afterCommit = null, ?TenantKey $lockTenant = null): mixed
    {
        $connection = $this->connection();
        $joined     = $connection->isTransactionActive();
        $unit       = $lockTenant instanceof TenantKey ? function () use ($connection, $lockTenant, $work): mixed {
            $this->lockTenant($connection, $lockTenant);

            return $work();
        } : $work;
        try {
            $result = $joined ? $this->runner($connection)->run($unit) : $this->outermost($connection, $unit);
        } finally {
            if ($lockTenant instanceof TenantKey) {
                $this->unlockTenant($connection, $lockTenant);
            }
        }
        if (!$afterCommit instanceof Closure) {
            return $result;
        }
        $then = static function () use ($afterCommit, $result): void {
            $afterCommit($result);
        };
        $native = $joined ? $connection->getNativeConnection() : null;
        if (is_object($native) && $connection->isTransactionActive()) {
            $this->afterCommit->hold($native, $then);
        } else {
            $this->afterCommit->run($then);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function inTransaction(): bool
    {
        return $this->connection()->isTransactionActive();
    }

    /**
     * {@inheritDoc}
     */
    public function codeExists(string $code): bool
    {
        $rows = $this->read(fn (Connection $c): array => $c->fetchFirstColumn(sprintf('SELECT code FROM %s WHERE code = ?', $this->table), [$code]));

        return in_array($code, $rows, true);
    }

    /**
     * {@inheritDoc}
     */
    public function insert(StoredLink $link): void
    {
        try {
            $this->connection()->insert($this->table, $this->row($link), $this->types());
        } catch (UniqueConstraintViolationException $exception) {
            throw new CodeTakenException(previous: $exception);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function update(StoredLink $link): void
    {
        $row = $this->row($link);
        unset($row['id'], $row['tenant_key'], $row['code'], $row['code_custom'], $row['kind'], $row['created_at'], $row['created_by']);
        $this->connection()->update($this->table, $row, ['id' => $link->id->value, 'tenant_key' => $link->tenant->value], $this->types());
    }

    /**
     * {@inheritDoc}
     */
    public function findActiveByCode(string $code, DateTimeImmutable $now): ?ResolvedLink
    {
        $sql  = sprintf('SELECT id, tenant_key, code, code_custom, target_url, kind, source_ref, active, expires_at, deleted_at FROM %s WHERE code = ?', $this->table);
        $rows = $this->read(static fn (Connection $c): array => $c->fetchAllAssociative($sql, [$code]));
        $at   = $this->utc($now);
        foreach ($rows as $row) {
            $expires = $row['expires_at'] === null ? null : $this->text($row['expires_at']);
            if ($row['code'] !== $code || !$this->bool($row['active']) || $row['deleted_at'] !== null || ($expires !== null && $this->comparable($expires) <= $at)) {
                continue;
            }
            try {
                return new ResolvedLink(
                    ShortLinkId::fromString($this->text($row['id'])),
                    TenantKey::fromString($this->text($row['tenant_key'])),
                    ShortCode::fromStored($code, $this->bool($row['code_custom'])),
                    TargetUrl::fromString($this->text($row['target_url'])),
                    LinkKind::fromString($this->text($row['kind'])),
                    ShortLinkState::Active,
                    $row['source_ref'] === null ? null : $this->text($row['source_ref']),
                );
            } catch (Throwable $exception) {
                $this->logger?->warning('nowo_short_link: unreadable link row for a redirect: {class}', ['class' => $exception::class]);

                return null;
            }
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function activeAfter(?string $afterId, int $limit): ActiveBatch
    {
        $sql    = sprintf('SELECT %s FROM %s WHERE active = ? AND deleted_at IS NULL AND id > ? ORDER BY id', self::COLUMNS, $this->table);
        $rows   = $this->read(static fn (Connection $c): array => $c->fetchAllAssociative($c->getDatabasePlatform()->modifyLimitQuery($sql, $limit), [true, $afterId ?? ''], [ParameterType::BOOLEAN, ParameterType::STRING]));
        $links  = [];
        $cursor = null;
        foreach ($rows as $row) {
            $cursor = $this->text($row['id']);
            try {
                $links[] = $this->hydrate($row);
            } catch (Throwable $exception) {
                $this->logger?->warning('nowo_short_link: unreadable link row skipped by the recheck: {class}', ['class' => $exception::class]);
            }
        }

        return new ActiveBatch($links, $cursor, count($rows));
    }

    /**
     * {@inheritDoc}
     */
    public function disableIfUnchanged(StoredLink $checked, string $reason, DateTimeImmutable $now): bool
    {
        $changed = $this->connection()->executeStatement(
            sprintf('UPDATE %s SET active = ?, disabled_reason = ?, updated_at = ? WHERE id = ? AND tenant_key = ? AND active = ? AND deleted_at IS NULL AND target_url = ?', $this->table),
            [false, $reason, $this->utc($now), $checked->id->value, $checked->tenant->value, true, $checked->target->toString()],
            [ParameterType::BOOLEAN, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::BOOLEAN, ParameterType::STRING],
        );

        return $changed === 1;
    }

    /**
     * {@inheritDoc}
     */
    public function isLive(ShortLinkId $id, TenantKey $tenant): bool
    {
        $tenants = $this->read(fn (Connection $c): array => $c->fetchFirstColumn(sprintf('SELECT tenant_key FROM %s WHERE id = ? AND deleted_at IS NULL', $this->table), [$id->value]));

        return in_array($tenant->value, $tenants, true);
    }

    /**
     * {@inheritDoc}
     */
    public function find(TenantKey $tenant, ShortLinkId $id): ?StoredLink
    {
        foreach ($this->select('tenant_key = ? AND id = ? AND deleted_at IS NULL', [$tenant->value, $id->value]) as $link) {
            if ($link->tenant->equals($tenant) && $link->id->equals($id)) {
                return $link;
            }
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function findForUpdate(TenantKey $tenant, ShortLinkId $id): ?StoredLink
    {
        $connection = $this->connection();
        $platform   = $connection->getDatabasePlatform();
        $lock       = $platform instanceof AbstractMySQLPlatform || $platform instanceof PostgreSQLPlatform ? ' FOR UPDATE' : '';
        $rows       = $connection->fetchAllAssociative(sprintf('SELECT %s FROM %s WHERE tenant_key = ? AND id = ? AND deleted_at IS NULL%s', self::COLUMNS, $this->table, $lock), [$tenant->value, $id->value]);
        foreach (array_map($this->hydrate(...), $rows) as $link) {
            if ($link->tenant->equals($tenant) && $link->id->equals($id)) {
                return $link;
            }
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function findBySource(TenantKey $tenant, LinkKind $kind, string $ref, ?int $position): ?StoredLink
    {
        $sql    = 'tenant_key = ? AND kind = ? AND source_ref = ? AND deleted_at IS NULL AND ' . ($position === null ? 'source_position IS NULL' : 'source_position = ?');
        $params = [$tenant->value, $kind->value, $ref];
        if ($position !== null) {
            $params[] = $position;
        }
        foreach ($this->select($sql . ' ORDER BY id', $params) as $link) {
            if ($link->tenant->equals($tenant) && $link->source?->ref === $ref) {
                return $link;
            }
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function countLive(TenantKey $tenant, LinkKind $kind): int
    {
        $count = $this->read(fn (Connection $c): mixed => $c->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE tenant_key = ? AND kind = ? AND deleted_at IS NULL', $this->table), [$tenant->value, $kind->value]));

        return (int) $this->text($count);
    }

    /**
     * {@inheritDoc}
     */
    public function list(TenantKey $tenant, ShortLinkListQuery $query, array $kinds, DateTimeImmutable $now): array
    {
        $where   = ['tenant_key = ?', 'deleted_at IS NULL'];
        $params  = [$tenant->value];
        $where[] = 'kind IN (' . implode(', ', array_map(static fn (): string => '?', $kinds)) . ')';
        $params  = [...$params, ...$kinds];
        $nowText = $this->utc($now);
        switch ($query->state) {
            case ShortLinkState::Active:
                $where[]  = 'active = ? AND (expires_at IS NULL OR expires_at > ?)';
                $params[] = true;
                $params[] = $nowText;
                break;
            case ShortLinkState::Disabled:
                $where[]  = 'active = ?';
                $params[] = false;
                break;
            case ShortLinkState::Expired:
                $where[]  = 'active = ? AND expires_at IS NOT NULL AND expires_at <= ?';
                $params[] = true;
                $params[] = $nowText;
                break;
            case ShortLinkState::Deleted:
                return [];
            case null:
                break;
        }
        if ($query->search !== null) {
            // Case-insensitive on every platform (PostgreSQL LIKE and MySQL binary collations are case-sensitive).
            $like    = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($query->search)) . '%';
            $where[] = "(LOWER(code) LIKE ? ESCAPE '!' OR LOWER(title) LIKE ? ESCAPE '!' OR target_host LIKE ? ESCAPE '!')";
            $params  = [...$params, $like, $like, $like];
        }
        if ($query->after instanceof ShortLinkId) {
            $where[]  = 'id < ?';
            $params[] = $query->after->value;
        }
        $links = [];
        foreach ($this->select(implode(' AND ', $where) . ' ORDER BY id DESC', $params, $query->limit + 1) as $link) {
            if ($link->tenant->equals($tenant)) {
                $links[] = $link;
            }
        }

        return $links;
    }

    /**
     * @param list<mixed> $params
     *
     * @return list<StoredLink>
     */
    private function select(string $condition, array $params, ?int $limit = null): array
    {
        $sql   = sprintf('SELECT %s FROM %s WHERE %s', self::COLUMNS, $this->table, $condition);
        $types = array_map(static fn (mixed $value): ParameterType => match (true) {
            is_int($value)                    => ParameterType::INTEGER,
            $value === true, $value === false => ParameterType::BOOLEAN,
            default                           => ParameterType::STRING,
        }, $params);
        $rows = $this->read(static function (Connection $c) use ($sql, $params, $types, $limit): array {
            $platformSql = $limit === null ? $sql : $c->getDatabasePlatform()->modifyLimitQuery($sql, $limit);

            return $c->fetchAllAssociative($platformSql, $params, $types);
        });

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): StoredLink
    {
        $decoded  = json_decode($this->text($row['metadata']), true);
        $metadata = is_array($decoded) && $decoded !== [] ? LinkMetadata::fromArray($decoded) : null;

        return new StoredLink(
            ShortLinkId::fromString($this->text($row['id'])),
            TenantKey::fromString($this->text($row['tenant_key'])),
            ShortCode::fromStored($this->text($row['code']), $this->bool($row['code_custom'])),
            TargetUrl::fromString($this->text($row['target_url'])),
            LinkTitle::fromString($this->nullableText($row['title'])),
            LinkKind::fromString($this->text($row['kind'])),
            $row['source_ref'] === null ? null : new LinkSource($this->text($row['source_ref']), $this->nullableText($row['source_label']), $row['source_position'] === null ? null : (int) $this->text($row['source_position'])),
            $metadata,
            $this->bool($row['active']),
            $row['expires_at'] === null ? null : Expiry::fromDateTime($this->date($row['expires_at'])),
            $this->nullableText($row['disabled_reason']),
            $this->date($row['created_at']),
            $this->date($row['updated_at']),
            $row['deleted_at'] === null ? null : $this->date($row['deleted_at']),
            $row['created_by'] === null ? null : ActorRef::fromStored($this->text($row['created_by'])),
            $row['updated_by'] === null ? null : ActorRef::fromStored($this->text($row['updated_by'])),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(StoredLink $link): array
    {
        return [
            'id'              => $link->id->value,
            'tenant_key'      => $link->tenant->value,
            'code'            => $link->code->value,
            'code_custom'     => $link->code->isCustom(),
            'target_url'      => $link->target->toString(),
            'target_host'     => $link->target->host(),
            'title'           => $link->title?->value,
            'kind'            => $link->kind->value,
            'source_ref'      => $link->source?->ref,
            'source_label'    => $link->source?->label,
            'source_position' => $link->source?->position,
            'metadata'        => $link->metadata?->toJson(),
            'active'          => $link->active,
            'expires_at'      => $link->expiry instanceof Expiry ? $this->utc($link->expiry->at) : null,
            'disabled_reason' => $link->disabledReason,
            'created_at'      => $this->utc($link->createdAt),
            'updated_at'      => $this->utc($link->updatedAt),
            'deleted_at'      => $link->deletedAt instanceof DateTimeImmutable ? $this->utc($link->deletedAt) : null,
            'created_by'      => $link->createdBy?->value,
            'updated_by'      => $link->updatedBy?->value,
        ];
    }

    /**
     * @return array<string, ParameterType|string>
     */
    private function types(): array
    {
        return ['code_custom' => Types::BOOLEAN, 'active' => Types::BOOLEAN, 'source_position' => ParameterType::INTEGER];
    }

    /**
     * Runs a read, reopening a lost connection once outside a transaction.
     *
     * @template T
     *
     * @param Closure(Connection): T $operation
     *
     * @return T
     */
    private function read(Closure $operation): mixed
    {
        return $this->retrying($this->connection(), $operation);
    }

    /**
     * @template T
     *
     * @return T
     */
    /**
     * One outermost write unit through the runner. A connection lost before the work returned rolled nothing in
     * (the server drops an uncommitted transaction with the session), so the unit runs once more on a new connection;
     * lost after it (during COMMIT) the outcome is unknown and the failure is rethrown: replaying could duplicate rows.
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    private function outermost(Connection $connection, Closure $work): mixed
    {
        $done = false;
        $unit = static function () use ($work, &$done): mixed {
            $result = $work();
            $done   = true;

            return $result;
        };
        try {
            return $this->runner($connection)->run($unit);
        } catch (ConnectionLost $exception) {
            if ($done || $connection->isTransactionActive()) {
                throw $exception;
            }
            $this->logger?->info('nowo_short_link: database connection lost before the write finished, reconnecting once.');
            $connection->close();

            return $this->runner($connection)->run($work);
        }
    }

    private function runner(Connection $connection): DoctrineTransactionRunner
    {
        return DoctrineTransactionRunner::forConnection($connection, 1, $this->logger);
    }

    /**
     * Takes the tenant's database lock inside the current transaction (rule 4: count-then-insert and find-then-insert
     * of one tenant are serialised on every app server). PostgreSQL: a transaction advisory lock, released by the
     * commit or rollback, waited for under a transaction-local `lock_timeout`; MySQL / MariaDB: `GET_LOCK` with a timeout, released by
     * {@see self::unlockTenant()} after the unit; SQLite: nothing (the database serialises writers: the loser of a race
     * gets a lock error, never a stale count).
     *
     * @throws TransientStorageFailure when the MySQL / MariaDB lock is not obtained within the timeout (PostgreSQL: a
     *                                 lock timeout, rethrown by the runner as a TransientStorageFailure)
     */
    private function lockTenant(Connection $connection, TenantKey $tenant): void
    {
        $platform = $connection->getDatabasePlatform();
        $hash     = hash('sha256', $tenant->value);
        if ($platform instanceof AbstractMySQLPlatform) {
            $got = $connection->fetchOne('SELECT GET_LOCK(?, ?)', [self::LOCK_NAME_PREFIX . substr($hash, 0, 40), $this->tenantLockTimeout], [ParameterType::STRING, ParameterType::INTEGER]);
            if ($this->text($got) !== '1') {
                throw new TransientStorageFailure(1, self::TENANT_LOCK_MESSAGE);
            }

            return;
        }
        if (!$platform instanceof PostgreSQLPlatform) {
            return;
        }
        // A bounded wait (rule 9): lock_timeout for this transaction only. A timeout (55P03) aborts the transaction or
        // the savepoint, whose rollback also restores the setting; on success it is restored at once.
        $previous = $connection->fetchOne("SELECT current_setting('lock_timeout')");
        $connection->fetchOne("SELECT set_config('lock_timeout', ?, true)", [(max(1, $this->tenantLockTimeout) * 1000) . 'ms']);
        $connection->fetchOne('SELECT pg_advisory_xact_lock(CAST(? AS BIGINT))', [(int) hexdec(substr($hash, 0, 15))], [ParameterType::INTEGER]);
        $connection->fetchOne("SELECT set_config('lock_timeout', ?, true)", [$this->text($previous)]);
    }

    /**
     * Releases the MySQL / MariaDB user-level lock of {@see self::lockTenant()} (PostgreSQL's ends with the
     * transaction). A failed release closes the connection, which drops the lock with the session: a worker never
     * keeps a tenant locked.
     */
    private function unlockTenant(Connection $connection, TenantKey $tenant): void
    {
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }
        try {
            $connection->fetchOne('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME_PREFIX . substr(hash('sha256', $tenant->value), 0, 40)]);
        } catch (Throwable $exception) {
            $this->logger?->warning('nowo_short_link: tenant lock release failed, closing the connection: {class}', ['class' => $exception::class]);
            $connection->close();
        }
    }

    private function retrying(Connection $connection, Closure $operation): mixed
    {
        try {
            return $operation($connection);
        } catch (ConnectionLost $exception) {
            if ($connection->isTransactionActive()) {
                throw $exception;
            }
            $this->logger?->info('nowo_short_link: database connection lost, reconnecting once.');
            $connection->close();

            return $operation($connection);
        }
    }

    private function connection(): Connection
    {
        $manager = $this->registry->getManager($this->entityManager);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('nowo_short_link.doctrine.entity_manager must name a Doctrine ORM entity manager.');
        }

        return $manager->getConnection();
    }

    /**
     * A stored date-time as `Y-m-d H:i:s` (PostgreSQL and MySQL may add fractions or a zone).
     */
    private function comparable(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function utc(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function date(mixed $value): DateTimeImmutable
    {
        return new DateTimeImmutable($this->text($value), new DateTimeZone('UTC'));
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function nullableText(mixed $value): ?string
    {
        return $value === null ? null : $this->text($value);
    }

    private function bool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
}
