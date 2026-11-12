<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

/**
 * Clicks of one link on one UTC day: people and machine traffic (bots, previews, scanners) apart.
 */
final readonly class DailyClicks
{
    /**
     * @param string $day `Y-m-d`
     */
    public function __construct(public string $day, public int $clicks, public int $machineClicks)
    {
    }
}
