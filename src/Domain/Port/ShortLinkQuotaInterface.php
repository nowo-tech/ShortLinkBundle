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
     */
    public function limitFor(TenantKey $tenant, LinkKind $kind): ?int;
}
