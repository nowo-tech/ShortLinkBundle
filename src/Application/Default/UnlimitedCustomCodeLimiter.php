<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application\Default;

use Nowo\ShortLinkBundle\Domain\Model\ActorRef;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Port\CustomCodeLimiterInterface;

/**
 * `code.custom_code_rate_limit.enabled: false`: every custom-code lookup is allowed (the host limits its forms itself).
 */
final readonly class UnlimitedCustomCodeLimiter implements CustomCodeLimiterInterface
{
    /**
     * Always true.
     */
    public function attempt(TenantKey $tenant, ?ActorRef $actor): bool
    {
        return true;
    }
}
