<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Port;

/**
 * Codes nobody may take. Default: the built-in list (contract §6.3) plus `code.reserved`.
 */
interface ReservedCodeProviderInterface
{
    /**
     * Whether this lowercased code is reserved.
     */
    public function isReserved(string $lowercasedCode): bool;
}
