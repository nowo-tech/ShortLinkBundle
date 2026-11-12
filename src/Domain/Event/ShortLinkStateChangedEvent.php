<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Event;

use Nowo\ShortLinkBundle\Domain\Model\ActorRef;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkState;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;

/**
 * A link was disabled or enabled (dispatched after commit). Expiry is not an event.
 */
final readonly class ShortLinkStateChangedEvent
{
    /** Disabled or enabled by a person. */
    public const REASON_MANUAL = 'manual';

    /** Disabled by the recheck command because the target is now refused. */
    public const REASON_RECHECK_REFUSED = 'recheck_refused';

    /**
     * @param string $reason {@see REASON_MANUAL} or {@see REASON_RECHECK_REFUSED}
     */
    public function __construct(
        public ShortLinkId $id,
        public TenantKey $tenant,
        public ShortLinkState $from,
        public ShortLinkState $to,
        public string $reason,
        public ?ActorRef $actor,
    ) {
    }
}
