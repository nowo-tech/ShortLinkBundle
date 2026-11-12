<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

use Nowo\ShortLinkBundle\Domain\Exception\InvalidShortLinkArgumentException;

use function mb_strlen;

/**
 * Title of a link: trimmed, control and bidirectional-override characters stripped, 1 to 255 characters.
 */
final readonly class LinkTitle
{
    private function __construct(public string $value)
    {
    }

    /**
     * A title, or null when nothing is left after cleaning.
     *
     * @throws InvalidShortLinkArgumentException when the cleaned title is longer than 255 characters
     */
    public static function fromString(?string $value): ?self
    {
        $value = $value === null ? '' : Text::clean($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > 255) {
            throw new InvalidShortLinkArgumentException('A title has at most 255 characters.');
        }

        return new self($value);
    }
}
