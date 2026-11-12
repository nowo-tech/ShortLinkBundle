<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Service;

use Nowo\ShortLinkBundle\Domain\Model\ClickDimension;
use Nowo\ShortLinkBundle\Domain\Model\DailyClicks;
use Nowo\ShortLinkBundle\Domain\Model\DateRange;
use Nowo\ShortLinkBundle\Domain\Model\DimensionClicks;
use Nowo\ShortLinkBundle\Domain\Model\LinkClickTotals;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;

/**
 * Click statistics (contract §3.3, §5.3): the daily rollup for rolled days and the raw rows for the others, so the
 * answers are exact before the rollup command runs. Every query filters on the tenant; another tenant's link has no
 * clicks. The caller checks access (the UI checks `View`).
 */
interface ShortLinkStatsReaderInterface
{
    /**
     * One row per day of the range (zeros included).
     *
     * @return list<DailyClicks>
     */
    public function daily(TenantKey $tenant, ShortLinkId $id, DateRange $range): array;

    /**
     * Top values of a dimension over the range, most clicked first.
     *
     * @return list<DimensionClicks>
     */
    public function breakdown(TenantKey $tenant, ShortLinkId $id, ClickDimension $dimension, DateRange $range, int $limit = 20): array;

    /**
     * Totals of several links (one grouped query per table); a null range = every day still stored.
     *
     * @param list<ShortLinkId> $ids
     *
     * @return array<string, LinkClickTotals> keyed by link id
     */
    public function totals(TenantKey $tenant, array $ids, ?DateRange $range = null): array;

    /**
     * E-mail log: the links of one source (send) with their totals, label, position and first / last click.
     *
     * @return list<LinkClickTotals>
     */
    public function bySource(TenantKey $tenant, LinkKind $kind, string $sourceRef): array;
}
