<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

use function is_string;
use function parse_url;
use function preg_match;

use const PHP_URL_HOST;

/**
 * The rule of `public_base_url`: an absolute http(s) URL with a host and no query, fragment or userinfo. Read by the
 * configuration tree (`Configuration::isBaseUrl()` delegates here) and by the short-URL builder, so Infrastructure does
 * not depend on the composition root (REQ-ARCH-002).
 */
final class PublicBaseUrl
{
    /**
     * Whether a value is an absolute http(s) URL with a host and no query, fragment or userinfo.
     */
    public static function isValid(mixed $value): bool
    {
        if (!is_string($value) || preg_match('#^https?://[^\s/?\#@]+(/[^\s?\#]*)?$#iD', $value) !== 1) {
            return false;
        }

        return is_string(parse_url($value, PHP_URL_HOST));
    }
}
