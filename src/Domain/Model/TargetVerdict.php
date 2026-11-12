<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

/**
 * Answer of a target guard: allowed, or refused with a reason.
 */
final readonly class TargetVerdict
{
    private function __construct(public bool $allowed, public ?TargetRefusal $refusal)
    {
    }

    /**
     * The target may be used.
     */
    public static function allow(): self
    {
        return new self(true, null);
    }

    /**
     * The target is refused.
     */
    public static function refuse(TargetRefusal $refusal): self
    {
        return new self(false, $refusal);
    }
}
