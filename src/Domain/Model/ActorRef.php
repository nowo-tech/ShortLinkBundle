<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

use Nowo\ShortLinkBundle\Domain\Exception\InvalidShortLinkArgumentException;

use function preg_match;
use function strlen;

/**
 * Opaque id of the person who created or edited a link (Beacon: the user UUID). Exact (rule LQ), 1 to 128 bytes of
 * valid UTF-8, no leading or trailing whitespace, control or format characters, no control or format character inside
 * except ZWNJ / ZWJ (U+200C / U+200D: Persian names, emoji sequences); zero-width spaces and bidirectional marks are refused:
 * MySQL / MariaDB PAD SPACE collations would otherwise make `u1 ` equal `u1` (PAD SPACE sweep 2026-11-12).
 */
final readonly class ActorRef
{
    private function __construct(public string $value)
    {
    }

    /**
     * @throws InvalidShortLinkArgumentException when the value breaks the rule above
     */
    public static function fromString(string $value): self
    {
        if ($value === '' || strlen($value) > 128 || preg_match('/^[\s\p{Cc}\p{Cf}]|[\s\p{Cc}\p{Cf}]$|\p{Cc}|(?![\x{200C}\x{200D}])\p{Cf}/u', $value) !== 0) {
            throw new InvalidShortLinkArgumentException('An actor id has 1 to 128 bytes of UTF-8, no leading or trailing whitespace and no control or format characters.');
        }

        return new self($value);
    }

    /**
     * An actor read back from storage: rows written before the whitespace rule (0.1.0) keep their exact value, so
     * listing them never fails; only the 0.1.0 rule (1 to 128 bytes, no control characters) applies.
     *
     * @throws InvalidShortLinkArgumentException when the stored value is empty, longer than 128 bytes or has control characters
     */
    public static function fromStored(string $value): self
    {
        if ($value === '' || strlen($value) > 128 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidShortLinkArgumentException('A stored actor id has 1 to 128 bytes and no control characters.');
        }

        return new self($value);
    }

    /**
     * Exact comparison.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
