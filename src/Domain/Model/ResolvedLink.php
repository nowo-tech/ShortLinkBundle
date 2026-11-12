<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

/**
 * Read model of the redirect hot path (one indexed row): what the redirect needs to answer and to record a click.
 */
final readonly class ResolvedLink
{
    /**
     * @param string|null $sourceRef source ref of a generated link (e-mail send), for the click event
     */
    public function __construct(
        public ShortLinkId $id,
        public TenantKey $tenant,
        public ShortCode $code,
        public TargetUrl $target,
        public LinkKind $kind,
        public ShortLinkState $state,
        public ?string $sourceRef = null,
    ) {
    }
}
