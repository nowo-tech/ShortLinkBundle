<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

use function preg_replace;
use function trim;

/**
 * Text helpers of the value objects.
 *
 * @internal
 */
final class Text
{
    /**
     * Removes C0 / C1 control characters and the bidirectional overrides / isolates (U+202A–U+202E, U+2066–U+2069),
     * then trims. Invalid UTF-8 becomes an empty string.
     */
    public static function clean(string $value): string
    {
        return trim((string) preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $value));
    }
}
