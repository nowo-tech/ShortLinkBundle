<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Event;

use DateTimeImmutable;
use Nowo\ShortLinkBundle\Domain\Model\CountryCode;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Model\UserAgentFamily;

/**
 * A click row was written (dispatched by the recorder, after the response in `terminate` mode). No IP, no referrer.
 */
final readonly class ShortLinkClickedEvent
{
    /**
     * @param string|null $sourceRef source ref of a generated link (e-mail send)
     * @param UserAgentFamily|null $uaFamily null when not stored (GPC or `store.user_agent_family: false`)
     */
    public function __construct(
        public ShortLinkId $id,
        public TenantKey $tenant,
        public LinkKind $kind,
        public ?string $sourceRef,
        public DateTimeImmutable $occurredAt,
        public bool $machine,
        public ?UserAgentFamily $uaFamily,
        public ?CountryCode $country,
    ) {
    }
}
