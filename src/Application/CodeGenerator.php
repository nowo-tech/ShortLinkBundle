<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application;

use Nowo\ShortLinkBundle\Domain\Model\ShortCode;
use Nowo\ShortLinkBundle\Domain\Port\CodeBlocklistInterface;
use Nowo\ShortLinkBundle\Domain\Port\ReservedCodeProviderInterface;

use function random_int;
use function strtolower;

/**
 * Random base62 codes from the CSPRNG (`random_int`, nothing seeded or kept between calls: worker-safe). Codes never
 * encode ids, time or the tenant. Reserved and blocklisted candidates are skipped; uniqueness is the caller's
 * (database) job.
 */
final readonly class CodeGenerator
{
    /** Base62 alphabet. */
    public const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /**
     * @param CodeBlocklistInterface|null $blocklist null when `code.blocklist` is false
     */
    public function __construct(
        private ReservedCodeProviderInterface $reserved,
        private ?CodeBlocklistInterface $blocklist,
    ) {
    }

    /**
     * A random code of this length (6 to 16) that is neither reserved nor blocklisted.
     */
    public function generate(int $length): ShortCode
    {
        do {
            $code = '';
            for ($i = 0; $i < $length; ++$i) {
                $code .= self::ALPHABET[random_int(0, 61)];
            }
            $lower = strtolower($code);
        } while ($this->reserved->isReserved($lower) || ($this->blocklist?->isBlocked($lower) ?? false));

        return ShortCode::random($code);
    }
}
