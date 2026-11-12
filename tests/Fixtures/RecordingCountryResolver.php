<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Fixtures;

use Nowo\ShortLinkBundle\Domain\Model\CountryCode;
use Nowo\ShortLinkBundle\Domain\Port\ClickCountryResolverInterface;
use RuntimeException;

/**
 * Country resolver of the tests: records the IPs it is asked about (test-only state).
 */
final class RecordingCountryResolver implements ClickCountryResolverInterface
{
    /** @var list<string> */
    public array $asked = [];

    public ?string $country = null;

    public bool $failing = false;

    public function countryFor(string $clientIp): ?CountryCode
    {
        $this->asked[] = $clientIp;
        if ($this->failing) {
            throw new RuntimeException('geo database down');
        }

        return $this->country === null ? null : CountryCode::fromString($this->country);
    }
}
