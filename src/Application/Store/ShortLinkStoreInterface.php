<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application\Store;

use Closure;
use DateTimeImmutable;
use Nowo\ShortLinkBundle\Domain\Exception\CodeTakenException;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\ResolvedLink;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkListQuery;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;

/**
 * Persistence of the links (DBAL in the bundle). Every tenant-scoped read filters on the tenant and re-checks it in
 * PHP; deleted rows (tombstones) are never returned, but their codes stay taken.
 *
 * @internal
 */
interface ShortLinkStoreInterface
{
    /**
     * Runs the work in a transaction (joining the host's one when open); the after-commit work runs after the
     * outermost commit and never when it rolls back.
     *
     * @template T
     *
     * @param Closure(): T $work
     * @param (Closure(T): void)|null $afterCommit
     *
     * @return T
     */
    public function transactional(Closure $work, ?Closure $afterCommit = null): mixed;

    /**
     * Whether a transaction is open on the store's connection (a host transaction the store would join).
     */
    public function inTransaction(): bool;

    /**
     * Whether any row (live or tombstone) holds exactly this code.
     */
    public function codeExists(string $code): bool;

    /**
     * Inserts a new row.
     *
     * @throws CodeTakenException when the unique code index refuses the row
     */
    public function insert(StoredLink $link): void;

    /**
     * Writes the mutable columns of an existing row.
     */
    public function update(StoredLink $link): void;

    /**
     * The active link holding exactly this code at this instant, or null (redirect hot path: one indexed row with the
     * columns the redirect needs; the state is decided from the raw row before anything is hydrated, and an unreadable
     * row answers null with a warning).
     */
    public function findActiveByCode(string $code, DateTimeImmutable $now): ?ResolvedLink;

    /**
     * The live row of the tenant with this id, or null.
     */
    public function find(TenantKey $tenant, ShortLinkId $id): ?StoredLink;

    /**
     * The live row of a generated link (tenant, kind, source ref, position), or null.
     */
    public function findBySource(TenantKey $tenant, LinkKind $kind, string $ref, ?int $position): ?StoredLink;

    /**
     * How many live rows the tenant has of this kind.
     */
    public function countLive(TenantKey $tenant, LinkKind $kind): int;

    /**
     * Live rows of a list page, newest first (`limit` + 1 rows at most so the caller knows about a next page).
     *
     * @param non-empty-list<string> $kinds kinds to list (the manager resolves the query kind against the listed kinds)
     *
     * @return list<StoredLink>
     */
    public function list(TenantKey $tenant, ShortLinkListQuery $query, array $kinds, DateTimeImmutable $now): array;
}
