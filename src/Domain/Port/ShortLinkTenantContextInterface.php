<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Port;

use Nowo\ShortLinkBundle\Domain\Model\TenantKey;

/**
 * Host port: the tenant of the current UI call (Beacon: the organization of the current page). Default: the single
 * tenant `default`. Implementations read their request state on every call and never cache it (worker mode).
 */
interface ShortLinkTenantContextInterface
{
    /**
     * Tenant of the current UI / API call; null = none (the UI answers 404).
     */
    public function current(): ?TenantKey;
}
