<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Fixtures\Ddd;

use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkQuotaInterface;

/**
 * DDD host quota port of the tests: a limit per tenant key.
 */
final class TestQuota implements ShortLinkQuotaInterface
{
    /** @var array<string, int> */
    public array $limits = [];

    public function limitFor(TenantKey $tenant, LinkKind $kind): ?int
    {
        return $this->limits[$tenant->value] ?? null;
    }
}
