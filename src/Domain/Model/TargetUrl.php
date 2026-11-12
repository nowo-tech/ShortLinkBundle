<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

use Nowo\ShortLinkBundle\Domain\Exception\TargetRefusedException;

use function idn_to_ascii;
use function is_array;
use function is_string;
use function parse_url;
use function preg_match;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;

use const IDNA_DEFAULT;
use const INTL_IDNA_VARIANT_UTS46;
use const PHP_URL_PATH;

/**
 * Target of a link (contract §3.1): an absolute `http` / `https` URL of at most 2 048 bytes after normalisation. The
 * scheme and host are lowercased, an IDN host becomes punycode (UTS #46), a trailing dot and the default port are
 * dropped; userinfo, control characters, whitespace and backslashes are refused (so a `Location` header can never be
 * split). Path, query and fragment are kept as given.
 */
final readonly class TargetUrl
{
    /** Absolute maximum length; `target.max_length` may lower it. */
    public const MAX_LENGTH = 2048;

    private function __construct(public string $value, private string $host)
    {
    }

    /**
     * Normalises and validates a submitted or stored target.
     *
     * @param int $maxLength at most {@see MAX_LENGTH}
     *
     * @throws TargetRefusedException with `Scheme`, `Userinfo` or `Length`
     */
    public static function fromString(string $url, int $maxLength = self::MAX_LENGTH): self
    {
        $url = trim($url, " \t\n\r\0\x0B");
        if ($url === '' || preg_match('/[\x00-\x20\x7F\\\\]|\x{00A0}|\x{1680}|[\x{2000}-\x{200B}]|\x{2028}|\x{2029}|\x{202F}|\x{205F}|\x{3000}|\x{FEFF}/u', $url) !== 0) {
            throw new TargetRefusedException(TargetRefusal::Scheme);
        }
        if (preg_match('#^https?://#i', $url) !== 1) {
            throw new TargetRefusedException(TargetRefusal::Scheme);
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw new TargetRefusedException(TargetRefusal::Scheme);
        }
        if (isset($parts['user']) || isset($parts['pass']) || str_contains(self::authority($url), '@')) {
            throw new TargetRefusedException(TargetRefusal::Userinfo);
        }

        $scheme = strtolower($parts['scheme']);
        $host   = self::normaliseHost($parts['host']);
        $port   = $parts['port'] ?? null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        $normalised = $scheme . '://' . $host . ($port === null ? '' : ':' . $port)
            . ($parts['path'] ?? '')
            . (isset($parts['query']) ? '?' . $parts['query'] : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
        if (strlen($normalised) > ($maxLength < self::MAX_LENGTH ? $maxLength : self::MAX_LENGTH)) {
            throw new TargetRefusedException(TargetRefusal::Length);
        }

        return new self($normalised, str_starts_with($host, '[') ? substr($host, 1, -1) : $host);
    }

    /**
     * Lowercased ASCII host (an IPv6 literal without brackets).
     */
    public function host(): string
    {
        return $this->host;
    }

    /**
     * Path of the URL (`/` when empty), for the self-chain check.
     */
    public function path(): string
    {
        $path = parse_url($this->value, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    /**
     * The normalised URL.
     */
    public function toString(): string
    {
        return $this->value;
    }

    /**
     * The authority part (between `//` and the first `/`, `?` or `#`).
     */
    private static function authority(string $url): string
    {
        preg_match('#^[a-z]+://([^/?\#]*)#i', $url, $match);

        return $match[1] ?? '';
    }

    /**
     * Lowercase ASCII host: IDN to punycode, one trailing dot dropped.
     *
     * @throws TargetRefusedException with `Scheme` when the host cannot be converted
     */
    private static function normaliseHost(string $host): string
    {
        if (str_starts_with($host, '[')) {
            return strtolower($host);
        }
        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }
        if (preg_match('/^[\x21-\x7E]+$/', $host) !== 1) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (!is_string($ascii) || $ascii === '') {
                throw new TargetRefusedException(TargetRefusal::Scheme);
            }
            $host = $ascii;
        }
        $host = strtolower($host);
        if (preg_match('/^[a-z0-9._~%!$&\'()*+,;=-]+$/', $host) !== 1) {
            throw new TargetRefusedException(TargetRefusal::Scheme);
        }

        return $host;
    }
}
