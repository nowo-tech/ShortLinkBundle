<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application\Default;

use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkTenantContextInterface;

/**
 * Default tenant context of single-tenant hosts: always `default`.
 */
final readonly class SingleTenantContext implements ShortLinkTenantContextInterface
{
    /**
     * The `default` tenant.
     */
    public function current(): TenantKey
    {
        return TenantKey::default();
    }
}
