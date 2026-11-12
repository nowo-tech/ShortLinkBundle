<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Event;

use Nowo\ShortLinkBundle\Domain\Model\GuardStage;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\TargetRefusal;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;

/**
 * The guard refused a target (abuse monitoring). Target host only, never the full URL.
 */
final readonly class ShortLinkTargetRefusedEvent
{
    /**
     * @param ShortLinkId|null $id the link, when the refusal concerns an existing one
     */
    public function __construct(
        public TenantKey $tenant,
        public GuardStage $stage,
        public TargetRefusal $refusal,
        public string $targetHost,
        public ?ShortLinkId $id,
    ) {
    }
}
