<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Event;

use Nowo\ShortLinkBundle\Domain\Model\ActorRef;
use Nowo\ShortLinkBundle\Domain\Model\ShortCode;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;

/**
 * A link became a tombstone (dispatched after commit); its code stays taken for the quarantine.
 */
final readonly class ShortLinkDeletedEvent
{
    /**
     * Builds the event.
     */
    public function __construct(
        public ShortLinkId $id,
        public TenantKey $tenant,
        public ShortCode $code,
        public ?ActorRef $actor,
    ) {
    }
}
