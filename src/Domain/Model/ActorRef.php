<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

use Nowo\ShortLinkBundle\Domain\Exception\InvalidShortLinkArgumentException;

use function preg_match;
use function strlen;

/**
 * Opaque id of the person who created or edited a link (Beacon: the user UUID). Exact (rule LQ), 1 to 128 bytes,
 * no control characters.
 */
final readonly class ActorRef
{
    private function __construct(public string $value)
    {
    }

    /**
     * @throws InvalidShortLinkArgumentException when the value is empty, longer than 128 bytes or has control characters
     */
    public static function fromString(string $value): self
    {
        if ($value === '' || strlen($value) > 128 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidShortLinkArgumentException('An actor id has 1 to 128 bytes and no control characters.');
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
