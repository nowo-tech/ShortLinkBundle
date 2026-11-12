<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Port;

/**
 * Generated codes skip these (Q-O9). Default: a small list of offensive substrings (en, es).
 */
interface CodeBlocklistInterface
{
    /**
     * Whether this lowercased code contains a blocked word.
     */
    public function isBlocked(string $lowercasedCode): bool;
}
