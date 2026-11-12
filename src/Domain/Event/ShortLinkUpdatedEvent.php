<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Event;

use Nowo\ShortLinkBundle\Domain\Model\ActorRef;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;

/**
 * A link was edited (dispatched after commit, only when something changed).
 */
final readonly class ShortLinkUpdatedEvent
{
    /**
     * @param list<string> $changedFields subset of `target`, `title`, `expires_at`
     */
    public function __construct(
        public ShortLinkId $id,
        public TenantKey $tenant,
        public array $changedFields,
        public string $oldTargetHost,
        public string $newTargetHost,
        public ?ActorRef $actor,
    ) {
    }
}
