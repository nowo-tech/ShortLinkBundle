<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

use function array_sum;

/**
 * Answer of {@see \Nowo\ShortLinkBundle\Domain\Service\ShortLinkUsageReaderInterface::count()}: the total and the count
 * of every tenant asked for (zero included), keyed by the exact tenant key.
 */
final readonly class ShortLinkCounts
{
    /** Sum of every tenant's count. */
    public int $total;

    /**
     * @param array<string, int> $byTenant count per tenant key
     */
    public function __construct(public array $byTenant)
    {
        $this->total = array_sum($byTenant);
    }

    /**
     * The count of one tenant (zero for a tenant that was not asked for).
     */
    public function forTenant(TenantKey $tenant): int
    {
        return $this->byTenant[$tenant->value] ?? 0;
    }
}
