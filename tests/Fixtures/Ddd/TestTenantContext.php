<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Fixtures\Ddd;

use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkTenantContextInterface;

/**
 * DDD host tenant context of the tests (the test sets the current tenant).
 */
final class TestTenantContext implements ShortLinkTenantContextInterface
{
    public ?TenantKey $tenant = null;

    public function current(): ?TenantKey
    {
        return $this->tenant;
    }
}
