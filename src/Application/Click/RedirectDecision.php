<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application\Click;

use Nowo\ShortLinkBundle\Domain\Event\ShortLinkTargetRefusedEvent;
use Nowo\ShortLinkBundle\Domain\Model\ResolvedLink;

/**
 * Result of {@see RedirectResolver::resolve()}: the link to follow, or none; a refused target carries the event to
 * dispatch after the response.
 */
final readonly class RedirectDecision
{
    /**
     * Builds the decision.
     */
    public function __construct(public ?ResolvedLink $link, public ?ShortLinkTargetRefusedEvent $refused)
    {
    }
}
