<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application\Default;

use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkQuotaInterface;

/**
 * Default quota: `quota.default_limit` for every tenant and counted kind.
 */
final readonly class ConfigQuota implements ShortLinkQuotaInterface
{
    /**
     * @param int|null $limit null = unlimited
     */
    public function __construct(private ?int $limit)
    {
    }

    /**
     * The configured limit.
     */
    public function limitFor(TenantKey $tenant, LinkKind $kind): ?int
    {
        return $this->limit;
    }
}
