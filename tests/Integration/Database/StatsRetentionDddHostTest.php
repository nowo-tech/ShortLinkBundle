<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration\Database;

use Nowo\ShortLinkBundle\Tests\Integration\Kernel\Host;

/**
 * The S3 suite on the DDD host (XML mapping on a dedicated entity manager, table prefix `ddd_`, host ports).
 */
final class StatsRetentionDddHostTest extends StatsRetentionTest
{
    protected function host(): Host
    {
        return Host::ddd();
    }
}
