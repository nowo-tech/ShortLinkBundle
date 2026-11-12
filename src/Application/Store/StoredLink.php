<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application\Store;

use DateTimeImmutable;
use Nowo\ShortLinkBundle\Domain\Model\ActorRef;
use Nowo\ShortLinkBundle\Domain\Model\Expiry;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\LinkMetadata;
use Nowo\ShortLinkBundle\Domain\Model\LinkSource;
use Nowo\ShortLinkBundle\Domain\Model\LinkTitle;
use Nowo\ShortLinkBundle\Domain\Model\ShortCode;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkState;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;

/**
 * One row of the `nowo_short_link` table as value objects (persistence model of the manager).
 *
 * @internal
 */
final readonly class StoredLink
{
    /**
     * Builds the row.
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
        public bool $active,
        public ?Expiry $expiry,
        public ?string $disabledReason,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt,
        public ?ActorRef $createdBy,
        public ?ActorRef $updatedBy,
    ) {
    }

    /**
     * State at this instant: deleted, disabled, expired or active.
     */
    public function stateAt(DateTimeImmutable $now): ShortLinkState
    {
        return match (true) {
            $this->deletedAt instanceof DateTimeImmutable                       => ShortLinkState::Deleted,
            !$this->active                                                      => ShortLinkState::Disabled,
            $this->expiry instanceof Expiry && $this->expiry->isReachedAt($now) => ShortLinkState::Expired,
            default                                                             => ShortLinkState::Active,
        };
    }

    /**
     * A copy with the editable fields replaced.
     */
    public function withEdit(TargetUrl $target, ?LinkTitle $title, ?Expiry $expiry, DateTimeImmutable $now, ?ActorRef $actor): self
    {
        return new self($this->id, $this->tenant, $this->code, $target, $title, $this->kind, $this->source, $this->metadata, $this->active, $expiry, $this->disabledReason, $this->createdAt, $now, $this->deletedAt, $this->createdBy, $actor);
    }

    /**
     * A copy enabled or disabled.
     *
     * @param string|null $disabledReason reason while disabled; null when enabling
     */
    public function withActive(bool $active, ?string $disabledReason, DateTimeImmutable $now, ?ActorRef $actor): self
    {
        return new self($this->id, $this->tenant, $this->code, $this->target, $this->title, $this->kind, $this->source, $this->metadata, $active, $this->expiry, $disabledReason, $this->createdAt, $now, $this->deletedAt, $this->createdBy, $actor);
    }

    /**
     * A tombstone copy (inactive, deleted now).
     */
    public function asTombstone(DateTimeImmutable $now, ?ActorRef $actor): self
    {
        return new self($this->id, $this->tenant, $this->code, $this->target, $this->title, $this->kind, $this->source, $this->metadata, false, $this->expiry, $this->disabledReason, $this->createdAt, $now, $now, $this->createdBy, $actor);
    }
}
