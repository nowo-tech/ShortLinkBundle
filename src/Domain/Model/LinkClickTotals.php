<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

use DateTimeImmutable;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkStatsReaderInterface;

/**
 * Click totals of one link (human and machine apart), with the first and last click when known. For
 * {@see ShortLinkStatsReaderInterface::bySource()} it also carries the link's code
 * and source label / position.
 */
final readonly class LinkClickTotals
{
    /**
     * @param DateTimeImmutable|null $firstClickAt exact for days not rolled yet, the start of the UTC day for rolled days
     */
    public function __construct(
        public ShortLinkId $id,
        public int $clicks,
        public int $machineClicks,
        public ?DateTimeImmutable $firstClickAt = null,
        public ?DateTimeImmutable $lastClickAt = null,
        public ?ShortCode $code = null,
        public ?LinkSource $source = null,
    ) {
    }
}
