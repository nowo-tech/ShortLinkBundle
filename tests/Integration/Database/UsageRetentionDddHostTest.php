<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration\Database;

use Nowo\ShortLinkBundle\Tests\Integration\Kernel\Host;

/**
 * The usage and retention suite on the DDD (id) host: XML mapping on a dedicated entity manager, table prefix `ddd_`,
 * host tenant / access / quota ports keyed by ids.
 */
final class UsageRetentionDddHostTest extends UsageRetentionTest
{
    protected function host(): Host
    {
        return Host::ddd();
    }
}
