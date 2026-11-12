<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

use Nowo\ShortLinkBundle\Domain\Exception\InvalidShortLinkArgumentException;

use function preg_match;

/**
 * Kind of a link: `manual` (people), `email` (generated for an e-mail send) or a host kind from `kinds`.
 */
final readonly class LinkKind
{
    /** Links people create. */
    public const MANUAL = 'manual';

    /** Links generated for an e-mail send. */
    public const EMAIL = 'email';

    private function __construct(public string $value)
    {
    }

    /**
     * @throws InvalidShortLinkArgumentException when the value does not match `^[a-z][a-z0-9_]{0,31}$`
     */
    public static function fromString(string $value): self
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $value) !== 1) {
            throw new InvalidShortLinkArgumentException('A link kind matches ^[a-z][a-z0-9_]{0,31}$.');
        }

        return new self($value);
    }

    /**
     * The `manual` kind.
     */
    public static function manual(): self
    {
        return new self(self::MANUAL);
    }

    /**
     * The `email` kind.
     */
    public static function email(): self
    {
        return new self(self::EMAIL);
    }

    /**
     * Exact comparison.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
