<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Fixtures;

use Closure;
use DateTimeImmutable;
use Nowo\ShortLinkBundle\Application\Store\ActiveBatch;
use Nowo\ShortLinkBundle\Application\Store\ShortLinkStoreInterface;
use Nowo\ShortLinkBundle\Application\Store\StoredLink;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\ResolvedLink;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkListQuery;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;

/**
 * Store whose first N code probes collide.
 */
class ScriptedStore implements ShortLinkStoreInterface
{
    public int $probes = 0;

    public function __construct(private readonly int $collisions = 0)
    {
    }

    public function transactional(Closure $work, ?Closure $afterCommit = null): mixed
    {
        $result = $work();
        if ($afterCommit instanceof Closure) {
            $afterCommit($result);
        }

        return $result;
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function codeExists(string $code): bool
    {
        return ++$this->probes <= $this->collisions;
    }

    public function insert(StoredLink $link): void
    {
    }

    public function update(StoredLink $link): void
    {
    }

    public function findActiveByCode(string $code, DateTimeImmutable $now): ?ResolvedLink
    {
        return null;
    }

    public function activeAfter(?string $afterId, int $limit): ActiveBatch
    {
        return new ActiveBatch([], null, 0);
    }

    public function disableIfUnchanged(StoredLink $checked, string $reason, DateTimeImmutable $now): bool
    {
        return false;
    }

    public function isLive(ShortLinkId $id, TenantKey $tenant): bool
    {
        return true;
    }

    public function find(TenantKey $tenant, ShortLinkId $id): ?StoredLink
    {
        return null;
    }

    public function findBySource(TenantKey $tenant, LinkKind $kind, string $ref, ?int $position): ?StoredLink
    {
        return null;
    }

    public function countLive(TenantKey $tenant, LinkKind $kind): int
    {
        return 0;
    }

    public function list(TenantKey $tenant, ShortLinkListQuery $query, array $kinds, DateTimeImmutable $now): array
    {
        return [];
    }
}
