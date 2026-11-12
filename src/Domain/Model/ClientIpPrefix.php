<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

use function chr;
use function filter_var;
use function inet_ntop;
use function inet_pton;
use function is_string;
use function ord;
use function str_repeat;
use function strlen;
use function substr;

use const FILTER_VALIDATE_IP;

/**
 * Truncated client network stored with a click (contract §3.1, §10): IPv4 `/24` and IPv6 `/48` by default, written as
 * `203.0.113.0/24` / `2001:db8:1234::/48`. The raw IP never leaves the request.
 */
final readonly class ClientIpPrefix
{
    private function __construct(public string $value)
    {
    }

    /**
     * Truncates a raw IP; null when the input is not an IP address. IPv4-mapped IPv6 addresses are treated as IPv4.
     *
     * @param int $ipv4Bits 16 to 32
     * @param int $ipv6Bits 32 to 64
     */
    public static function fromIp(?string $ip, int $ipv4Bits = 24, int $ipv6Bits = 48): ?self
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $binary = (string) inet_pton($ip);
        if (strlen($binary) === 16 && substr($binary, 0, 12) === str_repeat("\0", 10) . "\xFF\xFF") {
            $binary = substr($binary, 12);
        }
        $bits   = strlen($binary) === 4 ? $ipv4Bits : $ipv6Bits;
        $masked = '';
        for ($i = 0; $i < strlen($binary); ++$i) {
            $keep = $bits - $i * 8;
            $mask = $keep >= 8 ? 0xFF : ($keep <= 0 ? 0 : (0xFF << (8 - $keep)) & 0xFF);
            $masked .= chr(ord($binary[$i]) & $mask);
        }
        $text = inet_ntop($masked);

        return is_string($text) ? new self($text . '/' . $bits) : null;
    }
}
