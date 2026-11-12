<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Event;

use Nowo\ShortLinkBundle\Domain\Model\ActorRef;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\ShortCode;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;

/**
 * A link was created (dispatched after commit; target host only, never the full URL).
 */
final readonly class ShortLinkCreatedEvent
{
    /**
     * @param bool $system created by a programmatic caller (access not checked)
     */
    public function __construct(
        public ShortLinkId $id,
        public TenantKey $tenant,
        public ShortCode $code,
        public LinkKind $kind,
        public string $targetHost,
        public ?ActorRef $actor,
        public bool $system,
    ) {
    }
}
