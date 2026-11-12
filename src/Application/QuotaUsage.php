<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application;

/**
 * Live links of a counted kind against the tenant's plan limit ("12 of 50 links").
 */
final readonly class QuotaUsage
{
    /**
     * @param int $used live (non-deleted) links of the kind
     * @param int $limit the plan limit
     */
    public function __construct(public int $used, public int $limit)
    {
    }

    /**
     * Whether no link of the kind can be created any more.
     */
    public function isFull(): bool
    {
        return $this->used >= $this->limit;
    }
}
