<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

use Nowo\ShortLinkBundle\Domain\Exception\InvalidShortLinkArgumentException;

use function preg_match;

/**
 * ISO 3166-1 alpha-2 country of a click (from the host's resolver), uppercase.
 */
final readonly class CountryCode
{
    private function __construct(public string $value)
    {
    }

    /**
     * @throws InvalidShortLinkArgumentException when the value is not two uppercase letters
     */
    public static function fromString(string $value): self
    {
        if (preg_match('/^[A-Z]{2}$/D', $value) !== 1) {
            throw new InvalidShortLinkArgumentException('A country code is two uppercase letters (ISO 3166-1 alpha-2).');
        }

        return new self($value);
    }
}
