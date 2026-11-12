<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application\Default;

use Nowo\ShortLinkBundle\Domain\Model\ActorRef;
use Nowo\ShortLinkBundle\Domain\Port\CodeTakenLimiterInterface;

/**
 * `code.taken_rate_limit.enabled: false`: every "not available" answer is allowed (the host limits its forms itself).
 */
final readonly class UnlimitedCodeTakenLimiter implements CodeTakenLimiterInterface
{
    /**
     * Always true.
     */
    public function allows(?ActorRef $actor): bool
    {
        return true;
    }

    /**
     * Counts nothing.
     */
    public function hit(?ActorRef $actor): void
    {
    }
}
