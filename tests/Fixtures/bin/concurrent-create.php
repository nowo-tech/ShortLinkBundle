<?php

declare(strict_types=1);

/*
 * Child process of StandardHostTest::testTheQuotaIsExactUnderParallelCreates: boots its own kernel of the standard
 * host on the given database, waits for the start file, creates one counted link and prints "created" or "quota".
 *
 * Usage: php concurrent-create.php <database url> <kernel id> <start file>
 */

use Nowo\ShortLinkBundle\Domain\Exception\QuotaExceededException;
use Nowo\ShortLinkBundle\Domain\Model\CreateShortLink;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkManagerInterface;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\Host;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\TestKernel;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $url, $id, $start] = $argv;
$host                 = Host::standard();
$kernel               = new TestKernel($id, $host->bundleConfig(['quota' => ['default_limit' => 3]]), $url, $host->configure(), true);
$kernel->boot();
$manager = $kernel->getContainer()->get(ShortLinkManagerInterface::class);
assert($manager instanceof ShortLinkManagerInterface);
while (!is_file($start)) {
    usleep(1000);
}
try {
    $manager->create(TenantKey::default(), new CreateShortLink('https://example.com/' . $id, system: true));
    echo 'created';
} catch (QuotaExceededException) {
    echo 'quota';
}
$kernel->shutdown();
