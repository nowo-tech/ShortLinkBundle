<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application\Default;

use Nowo\ShortLinkBundle\Domain\Port\CodeBlocklistInterface;

use function str_contains;

/**
 * Default blocklist of generated codes (Q-O9): a small list of offensive substrings in English and Spanish. A
 * generated candidate that contains one is skipped; custom codes are not affected.
 */
final readonly class BuiltInCodeBlocklist implements CodeBlocklistInterface
{
    /** Lowercase substrings. */
    public const WORDS = [
        'fuck', 'shit', 'cunt', 'dick', 'cock', 'puss', 'slut', 'whore', 'nazi', 'porn', 'rape', 'anal', 'nigg', 'fag',
        'puta', 'puto', 'mierd', 'joder', 'polla', 'cono', 'pene', 'culo', 'verga', 'pija', 'zorra', 'marica', 'cabron',
    ];

    /**
     * Whether the lowercased code contains a blocked word.
     */
    public function isBlocked(string $lowercasedCode): bool
    {
        foreach (self::WORDS as $word) {
            if (str_contains($lowercasedCode, $word)) {
                return true;
            }
        }

        return false;
    }
}
