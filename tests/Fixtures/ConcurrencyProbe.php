<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Fixtures;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Host listener of the concurrency tests, driven by request headers:
 *
 * - `X-Test-Lock-Timeout` (kernel.request, before the conflict listener's commit mark): a 1 s lock wait timeout on the
 *   bundle's connection, so a blocked write fails fast instead of holding the test;
 * - `X-Test-Commit` (kernel.controller): an autocommit write on the bundle's connection before the controller, i.e. part
 *   of the request is committed when the controller then meets a lock conflict.
 */
final readonly class ConcurrencyProbe
{
    /** kernel.request priority: before ShortLinkConflictListener::REQUEST_PRIORITY (2048). */
    public const REQUEST_PRIORITY = 4096;

    public function __construct(private ManagerRegistry $registry, private ?string $entityManager, private string $clickTable)
    {
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest() && $event->getRequest()->headers->has('X-Test-Lock-Timeout')) {
            self::shortLockWaits($this->connection());
        }
    }

    public function onController(ControllerEvent $event): void
    {
        if ($event->isMainRequest() && $event->getRequest()->headers->has('X-Test-Commit')) {
            $this->connection()->insert($this->clickTable, [
                'link_id'    => '0190a6d0-0000-7000-8000-0000000c0de1',
                'tenant_key' => 'probe',
                'clicked_at' => '2026-11-12 09:00:00',
                'machine'    => 0,
            ]);
        }
    }

    /**
     * A 1 s lock wait timeout for the session (MySQL / MariaDB `innodb_lock_wait_timeout`, PostgreSQL `lock_timeout`).
     */
    public static function shortLockWaits(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $connection->executeStatement("SET lock_timeout = '1s'");
        }
    }

    private function connection(): Connection
    {
        $manager = $this->registry->getManager($this->entityManager);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('An ORM entity manager is expected.');
        }

        return $manager->getConnection();
    }
}
