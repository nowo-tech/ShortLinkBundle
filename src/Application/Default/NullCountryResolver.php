<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application\Default;

use Nowo\ShortLinkBundle\Domain\Model\CountryCode;
use Nowo\ShortLinkBundle\Domain\Port\ClickCountryResolverInterface;

/**
 * Default country resolver: no country (the `country` column stays empty).
 */
final readonly class NullCountryResolver implements ClickCountryResolverInterface
{
    /**
     * Always null.
     */
    public function countryFor(string $clientIp): ?CountryCode
    {
        return null;
    }
}
