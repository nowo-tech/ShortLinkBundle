<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Service;

use Nowo\ShortLinkBundle\Domain\Model\ShortLinkCountQuery;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkCounts;

/**
 * Read port for usage figures across tenants (billing, plan dashboards, seeding checks). A system API: it checks no
 * access, and the caller decides which tenants it may count. Read only, stateless (worker mode).
 */
interface ShortLinkUsageReaderInterface
{
    /**
     * Counts the links of the query's kind and state in each of its tenants, one grouped query per 500 tenants.
     * Tenant keys compare exactly. `Active` and `Expired` are judged against the clock at the call.
     */
    public function count(ShortLinkCountQuery $query): ShortLinkCounts;
}
