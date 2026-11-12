<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\DbalShortLinkStore;
use Nowo\ShortLinkBundle\Tests\Fixtures\MemoryLogger;
use PHPUnit\Framework\TestCase;

/**
 * {@see DbalShortLinkStore} failure paths: the reconnect-once rule and a manager that is not an ORM entity manager.
 */
final class DbalShortLinkStoreTest extends TestCase
{
    private const ID = '0190a6d0-0000-7000-8000-000000000001';

    public function testALostConnectionIsRetriedOnceOutsideATransaction(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->expects(self::once())->method('close');
        $calls = 0;
        $connection->method('fetchAllAssociative')->willReturnCallback(static function () use (&$calls): array {
            if (++$calls === 1) {
                throw self::lost();
            }

            return [];
        });
        $logger = new MemoryLogger();

        self::assertNull($this->store($this->registry($connection), $logger)->find(TenantKey::default(), ShortLinkId::fromString(self::ID)));
        self::assertSame(2, $calls);
        self::assertCount(1, $logger->records);
    }

    public function testALostConnectionInsideATransactionIsNotRetried(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::never())->method('close');
        $connection->method('fetchAllAssociative')->willThrowException(self::lost());

        $this->expectException(ConnectionLost::class);
        $this->store($this->registry($connection))->find(TenantKey::default(), ShortLinkId::fromString(self::ID));
    }

    public function testTheManagerMustBeAnOrmEntityManager(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createStub(ObjectManager::class));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Doctrine ORM entity manager');
        $this->store($registry)->codeExists('abc1234');
    }

    private function store(ManagerRegistry $registry, ?MemoryLogger $logger = null): DbalShortLinkStore
    {
        return new DbalShortLinkStore($registry, new AfterCommitQueue(), null, '', $logger);
    }

    private function registry(Connection $connection): ManagerRegistry
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);

        return $registry;
    }

    private static function lost(): ConnectionLost
    {
        return new ConnectionLost(new class('server has gone away') extends AbstractException {
        }, null);
    }
}
