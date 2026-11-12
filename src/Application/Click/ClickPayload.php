<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application\Click;

use DateTimeImmutable;
use Nowo\ShortLinkBundle\Domain\Model\ClientIpPrefix;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\ReferrerHost;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Model\UserAgentFamily;

/**
 * What the redirect gathered for one click, without I/O and without the raw IP (contract §7.3). Carried on a request
 * attribute (`terminate`), in a Messenger message, or recorded at once (`sync`).
 */
final readonly class ClickPayload
{
    /**
     * @param string|null $sourceRef source ref of a generated link
     * @param bool $gpc the visitor sent `Sec-GPC: 1`
     */
    public function __construct(
        public ShortLinkId $linkId,
        public TenantKey $tenant,
        public LinkKind $kind,
        public ?string $sourceRef,
        public DateTimeImmutable $clickedAt,
        public ?ClientIpPrefix $ipPrefix,
        public UserAgentFamily $uaFamily,
        public ?ReferrerHost $referrerHost,
        public bool $gpc,
    ) {
    }

    /**
     * Whether the click comes from a bot, a link preview or a scanner.
     */
    public function isMachine(): bool
    {
        return $this->uaFamily->isMachine();
    }
}
