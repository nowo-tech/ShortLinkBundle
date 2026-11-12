<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

use DateTimeInterface;

/**
 * What to create. The target is the submitted URL (normalised and guarded by the manager); `customCode` null means a
 * random code. `system: true` skips the access check (programmatic callers such as MailKit; never set it from an HTTP
 * request).
 */
final readonly class CreateShortLink
{
    /**
     * @param string $target absolute http(s) URL
     * @param string|null $customCode custom code (lowercased), null = random
     * @param string|null $title free title (cleaned, ≤ 255 characters)
     * @param DateTimeInterface|null $expiresAt must be in the future; null = never
     * @param LinkKind|null $kind null = `manual`
     * @param LinkSource|null $source origin of a generated link
     * @param LinkMetadata|null $metadata free host data
     * @param bool $active false creates the link disabled
     * @param bool $system skips the access check
     */
    public function __construct(
        public string $target,
        public ?string $customCode = null,
        public ?string $title = null,
        public ?DateTimeInterface $expiresAt = null,
        public ?LinkKind $kind = null,
        public ?LinkSource $source = null,
        public ?LinkMetadata $metadata = null,
        public bool $active = true,
        public bool $system = false,
    ) {
    }

    /**
     * The kind, `manual` by default.
     */
    public function kind(): LinkKind
    {
        return $this->kind ?? LinkKind::manual();
    }
}
