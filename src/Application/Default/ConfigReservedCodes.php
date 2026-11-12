<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application\Default;

use Nowo\ShortLinkBundle\Domain\Port\ReservedCodeProviderInterface;

use function array_fill_keys;
use function array_map;
use function strtolower;

/**
 * Default reserved codes: the built-in list of contract §6.3 plus `code.reserved` (case-insensitive). Codes starting
 * with `_` or `.` never match the code patterns, so they are refused anyway.
 */
final readonly class ConfigReservedCodes implements ReservedCodeProviderInterface
{
    /** Built-in reserved codes (contract §6.3). */
    public const BUILT_IN = [
        'admin', 'api', 'app', 'assets', 'auth', 'build', 'bundles', 'css', 'dashboard', 'docs', 'e', 'favicon', 'health',
        'help', 'img', 'js', 'legal', 'login', 'logout', 'mcp', 'media', 'metrics', 'new', 'oauth', 'privacy', 'register',
        'reset', 'robots', 's', 'security', 'settings', 'signin', 'signup', 'sitemap', 'static', 'status', 'support',
        'terms', 'unsubscribe', 'well-known', 'www',
    ];

    /** @var array<string, true> */
    private array $codes;

    /**
     * @param list<string> $extra `code.reserved`
     */
    public function __construct(array $extra = [])
    {
        $this->codes = array_fill_keys([...self::BUILT_IN, ...array_map(strtolower(...), $extra)], true);
    }

    /**
     * Whether the lowercased code is reserved.
     */
    public function isReserved(string $lowercasedCode): bool
    {
        return isset($this->codes[$lowercasedCode]);
    }
}
