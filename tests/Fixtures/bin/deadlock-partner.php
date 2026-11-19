<?php

declare(strict_types=1);

/*
 * Child process of ShortLinkFunctionalTestCase::testADeadlockVictimInsideAHostTransactionNeverCommitsHalfOfIt (audit
 * UY-1): boots its own kernel (its own connection), opens a transaction, creates e-mail links of the given source refs
 * (position 0; many rows, so InnoDB picks the other side as the deadlock victim), prints "inserted", then updates the
 * title of the given link (waiting for the parent, which holds its row lock) and commits. PostgreSQL: a long
 * deadlock_timeout, so the parent detects the deadlock and is the victim. Every wait is bounded (20 s).
 *
 * Usage: php deadlock-partner.php <database url> <kernel id> <standard|ddd> <tenant key> <locked link id> <ref>...
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

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $url, $id, $kind, $tenantKey, $locked] = $argv;
$refs                                     = array_slice($argv, 6);
$host                                     = $kind === Host::STANDARD ? Host::standard() : Host::ddd();
$tenant                                   = TenantKey::fromString($tenantKey);
$kernel                                   = new TestKernel($id, $host->bundleConfig(), $url, $host->configure(), $host->kind === Host::STANDARD);
$kernel->boot();
$manager = $kernel->getContainer()->get(ShortLinkManagerInterface::class);
assert($manager instanceof ShortLinkManagerInterface);
$connection = $host->connection($kernel);
$platform   = $connection->getDatabasePlatform();
if ($platform instanceof AbstractMySQLPlatform) {
    $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 20');
} elseif ($platform instanceof PostgreSQLPlatform) {
    $connection->executeStatement("SET lock_timeout = '20s'");
    $connection->executeStatement("SET deadlock_timeout = '5s'");
}
$connection->beginTransaction();
$manager->createMany($tenant, array_map(
    static fn (string $ref): CreateShortLink => new CreateShortLink('https://example.com/partner/' . $ref, kind: LinkKind::email(), source: new LinkSource($ref, null, 0), system: true),
    $refs,
));
echo "inserted\n";
$connection->executeStatement(sprintf('UPDATE %snowo_short_link SET title = ? WHERE id = ?', $host->tablePrefix()), ['partner', $locked]);
$connection->commit();
echo "committed\n";
$kernel->shutdown();
