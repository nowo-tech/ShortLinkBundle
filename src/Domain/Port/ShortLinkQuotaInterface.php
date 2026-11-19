<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Port;

use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;

/**
 * Host port: plan limits (Beacon: the "Short links" plan module). Default: `quota.default_limit`.
 */
interface ShortLinkQuotaInterface
{
    /**
     * Max non-deleted links of this kind for the tenant; null = unlimited. Only counted kinds are asked.
     *
     * Called while the tenant's database lock is held (REQ-RUNTIME-003 rule 9): it must answer fast, from memory, a
     * cache or one local query. No HTTP or other slow I/O here: every create of the tenant waits for it (and gives up
     * after `quota.lock_timeout`).
     */
    public function limitFor(TenantKey $tenant, LinkKind $kind): ?int;
}
