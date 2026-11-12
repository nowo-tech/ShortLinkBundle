<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Port;

use Nowo\ShortLinkBundle\Domain\Model\CountryCode;

/**
 * Host port: country of a click (Beacon: none, the default null resolver). A local database lookup only: no HTTP.
 */
interface ClickCountryResolverInterface
{
    /**
     * Receives the raw IP after the response is sent; must not store it. Timeout ≤ 50 ms (REQ-RUNTIME-001).
     */
    public function countryFor(string $clientIp): ?CountryCode;
}
