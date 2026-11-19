<?php

declare(strict_types=1);

/*
 * Child process of ShortLinkFunctionalTestCase::testTheQuotaIsExactUnderParallelCreates: boots its own kernel of the
 * given host (standard: symfony/lock + the database lock; ddd: the database lock only) on the given database with a plan
 * limit of 3, waits for the start file, creates one counted link and prints "created" or "quota".
 *
 * Usage: php concurrent-create.php <database url> <kernel id> <start file> [standard|ddd] [tenant key]
 */

use Nowo\ShortLinkBundle\Domain\Exception\QuotaExceededException;
use Nowo\ShortLinkBundle\Domain\Model\CreateShortLink;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkManagerInterface;
use Nowo\ShortLinkBundle\Tests\Fixtures\Ddd\TestQuota;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\Host;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\TestKernel;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $url, $id, $start] = $argv;
$host                 = ($argv[4] ?? Host::STANDARD) === Host::STANDARD ? Host::standard() : Host::ddd();
$tenant               = TenantKey::fromString($argv[5] ?? 'default');
$kernel               = new TestKernel($id, $host->bundleConfig(['quota' => ['default_limit' => 3]]), $url, $host->configure(), $host->kind === Host::STANDARD);
$kernel->boot();
if ($host->kind !== Host::STANDARD) {
    $quota = $kernel->getContainer()->get(TestQuota::class);
    assert($quota instanceof TestQuota);
    $quota->limits[$tenant->value] = 3;
}
$manager = $kernel->getContainer()->get(ShortLinkManagerInterface::class);
assert($manager instanceof ShortLinkManagerInterface);
while (!is_file($start)) {
    usleep(1000);
}
try {
    $manager->create($tenant, new CreateShortLink('https://example.com/' . $id, system: true));
    echo 'created';
} catch (QuotaExceededException) {
    echo 'quota';
}
$kernel->shutdown();
