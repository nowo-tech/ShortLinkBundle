<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\ShortLinkBundle\Infrastructure\Doctrine\ClickTablesSchemaListener;
use Nowo\ShortLinkBundle\Infrastructure\Doctrine\ShortLinkMetadataListener;

/**
 * Connections and table names of the bundle tables: the link table on the entity manager's connection, the click
 * tables on `doctrine.connection` (or the same connection). Queries never join across the two. Stateless.
 *
 * @internal
 */
final readonly class ClickTables
{
    /** Link table (prefixed). */
    public string $links;

    /** Raw click table (prefixed). */
    public string $clicks;

    /** Daily rollup table (prefixed). */
    public string $daily;

    /** Rolled-days table (prefixed). */
    public string $days;

    /**
     * @param string|null $connection `doctrine.connection`; null = the entity manager's connection
     * @param string|null $entityManager `doctrine.entity_manager`; null = the default entity manager
     */
    public function __construct(
        private ManagerRegistry $registry,
        private ?string $connection = null,
        private ?string $entityManager = null,
        string $tablePrefix = '',
    ) {
        $this->links  = $tablePrefix . ShortLinkMetadataListener::TABLE;
        $this->clicks = $tablePrefix . ClickTablesSchemaListener::CLICK_TABLE;
        $this->daily  = $tablePrefix . ClickTablesSchemaListener::DAILY_TABLE;
        $this->days   = $tablePrefix . ClickTablesSchemaListener::ROLLUP_DAY_TABLE;
    }

    /**
     * Whether the click tables live on the link table's connection (one transaction can cover both).
     */
    public function sharesConnection(): bool
    {
        return $this->connection === null || $this->clickConnection() === $this->linkConnection();
    }

    /**
     * Connection of the click tables.
     */
    public function clickConnection(): Connection
    {
        if ($this->connection !== null) {
            $connection = $this->registry->getConnection($this->connection);
            if (!$connection instanceof Connection) {
                throw new LogicException('nowo_short_link.doctrine.connection must name a Doctrine DBAL connection.');
            }

            return $connection;
        }

        return $this->linkConnection();
    }

    /**
     * Connection of the link table.
     */
    public function linkConnection(): Connection
    {
        $manager = $this->registry->getManager($this->entityManager);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('nowo_short_link.doctrine.entity_manager must name a Doctrine ORM entity manager.');
        }

        return $manager->getConnection();
    }
}
