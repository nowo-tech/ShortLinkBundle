<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

use DateTimeInterface;

/**
 * The editable fields of a link, all replaced (the code is immutable: a new code is a new link).
 */
final readonly class UpdateShortLink
{
    /**
     * @param string $target absolute http(s) URL (normalised and guarded again)
     * @param string|null $title null or empty removes the title
     * @param DateTimeInterface|null $expiresAt must be in the future; null = never
     * @param bool $system skips the access check
     */
    public function __construct(
        public string $target,
        public ?string $title = null,
        public ?DateTimeInterface $expiresAt = null,
        public bool $system = false,
    ) {
    }
}
