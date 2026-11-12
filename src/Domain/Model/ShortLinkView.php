<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

use DateTimeImmutable;

/**
 * Read model of one link for lists and hosts.
 */
final readonly class ShortLinkView
{
    /**
     * @param string|null $disabledReason `manual` or `recheck_refused` while disabled
     * @param string $shortUrl absolute short URL
     */
    public function __construct(
        public ShortLinkId $id,
        public TenantKey $tenant,
        public ShortCode $code,
        public TargetUrl $target,
        public ?LinkTitle $title,
        public LinkKind $kind,
        public ?LinkSource $source,
        public ?LinkMetadata $metadata,
        public ShortLinkState $state,
        public ?Expiry $expiry,
        public ?string $disabledReason,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?ActorRef $createdBy,
        public ?ActorRef $updatedBy,
        public string $shortUrl,
    ) {
    }
}
