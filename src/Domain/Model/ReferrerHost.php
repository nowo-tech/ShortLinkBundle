<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

use function idn_to_ascii;
use function is_string;
use function parse_url;
use function preg_match;
use function rtrim;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;

use const IDNA_DEFAULT;
use const INTL_IDNA_VARIANT_UTS46;

/**
 * Host of the page where the short link was clicked (incoming `Referer`), lowercased punycode, at most 253 characters.
 * Never the path or the query (F9).
 */
final readonly class ReferrerHost
{
    private function __construct(public string $value)
    {
    }

    /**
     * The host of an absolute http(s) `Referer`, or null.
     */
    public static function fromReferer(?string $referer): ?self
    {
        if ($referer === null || strlen($referer) > 4096) {
            return null;
        }
        $parts = parse_url(trim($referer));
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || preg_match('/^https?$/iD', $parts['scheme']) !== 1) {
            return null;
        }
        $host = rtrim($parts['host'], '.');
        if (preg_match('/^[\x21-\x7E]+$/D', $host) !== 1) {
            $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        }
        if (!is_string($host)) {
            return null;
        }
        $host = strtolower($host);
        if (str_starts_with($host, '[')) {
            $host = substr($host, 1, -1);
        }

        return preg_match('/^[a-z0-9.:_-]{1,253}$/D', $host) === 1 ? new self($host) : null;
    }
}
