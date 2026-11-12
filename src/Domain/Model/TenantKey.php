<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

use Nowo\ShortLinkBundle\Domain\Exception\InvalidShortLinkArgumentException;

use function preg_match;

/**
 * Tenant of a link (Beacon: the organization UUID). Exact: no trim, no case folding (rule LQ).
 */
final readonly class TenantKey
{
    /** Key of single-tenant hosts. */
    public const DEFAULT = 'default';

    private function __construct(public string $value)
    {
    }

    /**
     * @throws InvalidShortLinkArgumentException when the value does not match `^[A-Za-z0-9._:-]{1,64}$`
     */
    public static function fromString(string $value): self
    {
        if (preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $value) !== 1) {
            throw new InvalidShortLinkArgumentException('A tenant key has 1 to 64 characters [A-Za-z0-9._:-].');
        }

        return new self($value);
    }

    /**
     * The tenant of single-tenant hosts, `default`.
     */
    public static function default(): self
    {
        return new self(self::DEFAULT);
    }

    /**
     * Exact comparison.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
