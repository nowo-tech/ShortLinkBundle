<?php

declare(strict_types=1);

/*
 * Child process of ShortLinkFunctionalTestCase's source-race tests (audit UK-SL-2): boots its own kernel (its own
 * connection) of the given host, waits for the start file (or starts at once with "-"), opens a host transaction,
 * calls createMany() with e-mail links of the given source refs (position 0), prints the link ids (one per line, then
 * "inserted"), holds the transaction open for the given milliseconds and commits. A lock conflict (an InnoDB deadlock
 * among three or more racers) rolls the host transaction back and prints "conflict". A session lock wait timeout of 20 s
 * bounds every wait, so a hang fails fast.
 *
 * Usage: php source-race.php <database url> <kernel id> <standard|ddd> <tenant key> <start file|-> <hold ms> <ref>...
 */

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Nowo\ShortLinkBundle\Domain\Model\CreateShortLink;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\LinkSource;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkManagerInterface;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\Host;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\TestKernel;
use Nowo\TransactionContract\TransientStorageFailure;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $url, $id, $kind, $tenantKey, $start, $hold] = $argv;
$refs                                           = array_slice($argv, 7);
$host                                           = $kind === Host::STANDARD ? Host::standard() : Host::ddd();
$tenant                                         = TenantKey::fromString($tenantKey);
$kernel                                         = new TestKernel($id, $host->bundleConfig(), $url, $host->configure(), $host->kind === Host::STANDARD);
$kernel->boot();
$manager = $kernel->getContainer()->get(ShortLinkManagerInterface::class);
assert($manager instanceof ShortLinkManagerInterface);
$connection = $host->connection($kernel);
$platform   = $connection->getDatabasePlatform();
if ($platform instanceof AbstractMySQLPlatform) {
    $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 20');
} elseif ($platform instanceof PostgreSQLPlatform) {
    $connection->executeStatement("SET lock_timeout = '20s'");
}
while ($start !== '-' && !is_file($start)) {
    usleep(1000);
}
$connection->beginTransaction();
try {
    $views = $manager->createMany($tenant, array_map(
        static fn (string $ref): CreateShortLink => new CreateShortLink('https://example.com/' . $id . '/' . $ref, kind: LinkKind::email(), source: new LinkSource($ref, null, 0), system: true),
        $refs,
    ));
} catch (TransientStorageFailure) {
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }
    echo "conflict\n";
    $kernel->shutdown();
    exit(0);
}
foreach ($views as $view) {
    echo $view->id->value, "\n";
}
echo "inserted\n";
usleep(((int) $hold) * 1000);
$connection->commit();
$kernel->shutdown();
