<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

use Nowo\ShortLinkBundle\Domain\Exception\InvalidShortLinkArgumentException;

use function preg_match;
use function strtolower;

/**
 * A short code. Random codes are base62 and case-sensitive (`^[A-Za-z0-9]{6,16}$`); custom codes are lowercase
 * `[a-z0-9-]`, 3 to 32 characters, without a leading, trailing or double hyphen (Q-O8). Compared with `===` only.
 */
final readonly class ShortCode
{
    /** Pattern of random codes. */
    public const RANDOM_PATTERN = '/^[A-Za-z0-9]{6,16}$/D';

    /** Pattern of custom codes (after lowercasing). */
    public const CUSTOM_PATTERN = '/^[a-z0-9](?:[a-z0-9]|-(?!-)){1,30}[a-z0-9]$/D';

    private function __construct(public string $value, private bool $custom)
    {
    }

    /**
     * A generated base62 code.
     *
     * @throws InvalidShortLinkArgumentException when the value is not base62 of 6 to 16 characters
     */
    public static function random(string $value): self
    {
        if (preg_match(self::RANDOM_PATTERN, $value) !== 1) {
            throw new InvalidShortLinkArgumentException('A random code has 6 to 16 characters [A-Za-z0-9].');
        }

        return new self($value, false);
    }

    /**
     * A code chosen by a person; the input is lowercased first.
     *
     * @throws InvalidShortLinkArgumentException when the lowercased value breaks the custom-code rule
     */
    public static function custom(string $value): self
    {
        $value = strtolower($value);
        if (preg_match(self::CUSTOM_PATTERN, $value) !== 1) {
            throw new InvalidShortLinkArgumentException('A custom code has 3 to 32 characters [a-z0-9-], without a leading, trailing or double hyphen.');
        }

        return new self($value, true);
    }

    /**
     * A code read back from storage.
     *
     * @throws InvalidShortLinkArgumentException when the stored value breaks its rule
     */
    public static function fromStored(string $value, bool $custom): self
    {
        if ($custom) {
            if (preg_match(self::CUSTOM_PATTERN, $value) !== 1) {
                throw new InvalidShortLinkArgumentException('A custom code has 3 to 32 characters [a-z0-9-], without a leading, trailing or double hyphen.');
            }

            return new self($value, true);
        }

        return self::random($value);
    }

    /**
     * Whether a person chose the code.
     */
    public function isCustom(): bool
    {
        return $this->custom;
    }

    /**
     * Exact, case-sensitive comparison.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
