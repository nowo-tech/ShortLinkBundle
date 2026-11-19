<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Fixtures;

use Closure;
use Nowo\ShortLinkBundle\Application\Store\StoredLink;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;

/**
 * Store whose row changed between the manager's first read ({@see self::find()}) and the read under the row lock
 * ({@see self::findForUpdate()}): a concurrent request committed in between. Records the writes, the after-commit runs
 * and the tenant lock asked for.
 */
final class RacingStore extends ScriptedStore
{
    /** @var list<StoredLink> */
    public array $updated = [];

    /** @var list<TenantKey|null> */
    public array $locks = [];

    /** The live link of the same source that another connection committed first (insert answers it). */
    public ?StoredLink $winner = null;

    public function __construct(public ?StoredLink $before = null, public ?StoredLink $fresh = null)
    {
        parent::__construct();
    }

    public function transactional(Closure $work, ?Closure $afterCommit = null, ?TenantKey $lockTenant = null): mixed
    {
        $this->locks[] = $lockTenant;

        return parent::transactional($work, $afterCommit, $lockTenant);
    }

    public function insert(StoredLink $link): StoredLink
    {
        return $this->winner ?? $link;
    }

    public function update(StoredLink $link): void
    {
        $this->updated[] = $link;
    }

    public function find(TenantKey $tenant, ShortLinkId $id): ?StoredLink
    {
        return $this->before;
    }

    public function findForUpdate(TenantKey $tenant, ShortLinkId $id): ?StoredLink
    {
        return $this->fresh;
    }
}
