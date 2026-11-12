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
use Psr\Log\LoggerInterface;
use Throwable;

use function array_map;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_object;
use function is_scalar;
use function json_decode;
use function sprintf;
use function str_replace;

/**
 * DBAL store of the links (contract §5.1): plain SQL on the entity manager's connection, never the ORM unit of work,
 * so the host's EntityManager is never flushed (rule 6). Writes join an open host transaction and hold their
 * after-commit work until the outermost commit ({@see AfterCommitQueue}). Every tenant-scoped read filters on the
 * tenant and re-checks tenant, id and code with `===` in PHP (rule LQ). Stateless (worker mode); a lost connection is
 * reopened once outside a transaction.
 *
 * @internal
 */
final readonly class DbalShortLinkStore implements ShortLinkStoreInterface
{
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
    ) {
        $this->table = $tablePrefix . ShortLinkMetadataListener::TABLE;
    }

    /**
     * {@inheritDoc}
     */
    public function transactional(Closure $work, ?Closure $afterCommit = null): mixed
    {
        $connection = $this->connection();
        $joined     = $connection->isTransactionActive();
        $result     = $joined ? $connection->transactional($work) : $this->retrying($connection, static fn (Connection $c): mixed => $c->transactional($work));
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
            $like    = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query->search) . '%';
            $where[] = "(code LIKE ? ESCAPE '!' OR title LIKE ? ESCAPE '!' OR target_host LIKE ? ESCAPE '!')";
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
            $row['created_by'] === null ? null : ActorRef::fromString($this->text($row['created_by'])),
            $row['updated_by'] === null ? null : ActorRef::fromString($this->text($row['updated_by'])),
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
     * @param Closure(Connection): T $operation
     *
     * @return T
     */
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
