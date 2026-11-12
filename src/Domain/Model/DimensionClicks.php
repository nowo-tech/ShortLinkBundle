<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

/**
 * Clicks of one value of a breakdown (a referrer host, a country, a browser family). `''` is unknown, `~other` the
 * referrer hosts beyond `rollup.referrer_top_n`.
 */
final readonly class DimensionClicks
{
    /** Value of the referrer hosts beyond the top N of a rolled day. */
    public const OTHER = '~other';

    /**
     * Builds the row.
     */
    public function __construct(public string $value, public int $clicks, public int $machineClicks)
    {
    }
}
