<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Nowo\ShortLinkBundle\Infrastructure\Doctrine\Entity\ShortLinkRecord;

/**
 * Doctrine `postGenerateSchema` listener: adds the DBAL click tables (contract §5.2, no ORM entity) to the schema of
 * the entity manager that maps {@see ShortLinkRecord}, so `doctrine:migrations:diff` creates them with the link table.
 * Names get `doctrine.table_prefix`; identifier columns get `utf8mb4_bin` on MySQL / MariaDB.
 *
 * @internal
 */
final readonly class ClickTablesSchemaListener
{
    /** Raw clicks (unprefixed). */
    public const CLICK_TABLE = 'nowo_short_link_click';

    /** Daily rollup (unprefixed). */
    public const DAILY_TABLE = 'nowo_short_link_click_daily';

    /** Rolled days (unprefixed). */
    public const ROLLUP_DAY_TABLE = 'nowo_short_link_rollup_day';

    /**
     * @param string $tablePrefix `doctrine.table_prefix`
     */
    public function __construct(private string $tablePrefix = '')
    {
    }

    /**
     * Doctrine `postGenerateSchema` listener.
     */
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $manager = $args->getEntityManager();
        if ($manager->getMetadataFactory()->isTransient(ShortLinkRecord::class)) {
            return;
        }
        $this->addTables($args->getSchema(), $manager->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform);
    }

    /**
     * Adds the three click tables to a schema (also used by tests and hosts that build the schema themselves).
     */
    public function addTables(Schema $schema, bool $mysql): void
    {
        $p = $this->tablePrefix;

        $click = $schema->createTable($p . self::CLICK_TABLE);
        $click->addColumn('id', Types::BIGINT, ['autoincrement' => true]);
        $this->binary($click->addColumn('link_id', Types::STRING, ['length' => 36]), $mysql);
        $this->binary($click->addColumn('tenant_key', Types::STRING, ['length' => 64]), $mysql);
        $click->addColumn('clicked_at', Types::DATETIME_IMMUTABLE);
        $click->addColumn('ip_prefix', Types::STRING, ['length' => 43, 'notnull' => false]);
        $click->addColumn('ua_family', Types::STRING, ['length' => 16, 'notnull' => false]);
        $click->addColumn('referrer_host', Types::STRING, ['length' => 253, 'notnull' => false]);
        $click->addColumn('country', Types::STRING, ['length' => 2, 'fixed' => true, 'notnull' => false]);
        $click->addColumn('machine', Types::BOOLEAN);
        $click->setPrimaryKey(['id']);
        $click->addIndex(['link_id', 'clicked_at'], $p . 'idx_short_link_click_link');
        $click->addIndex(['tenant_key', 'clicked_at'], $p . 'idx_short_link_click_tenant');
        $click->addIndex(['clicked_at'], $p . 'idx_short_link_click_time');

        $daily = $schema->createTable($p . self::DAILY_TABLE);
        $this->binary($daily->addColumn('link_id', Types::STRING, ['length' => 36]), $mysql);
        $this->binary($daily->addColumn('tenant_key', Types::STRING, ['length' => 64]), $mysql);
        $daily->addColumn('day', Types::DATE_IMMUTABLE);
        $daily->addColumn('dimension', Types::STRING, ['length' => 16]);
        $this->binary($daily->addColumn('value', Types::STRING, ['length' => 253, 'default' => '']), $mysql);
        $daily->addColumn('clicks', Types::INTEGER, ['default' => 0]);
        $daily->addColumn('machine_clicks', Types::INTEGER, ['default' => 0]);
        $daily->setPrimaryKey(['link_id', 'day', 'dimension', 'value']);
        $daily->addIndex(['tenant_key', 'day'], $p . 'idx_short_link_daily_tenant');
        $daily->addIndex(['day'], $p . 'idx_short_link_daily_day');

        $days = $schema->createTable($p . self::ROLLUP_DAY_TABLE);
        $days->addColumn('day', Types::DATE_IMMUTABLE);
        $days->addColumn('rolled_at', Types::DATETIME_IMMUTABLE);
        $days->setPrimaryKey(['day']);
    }

    private function binary(Column $column, bool $mysql): void
    {
        if ($mysql) {
            $column->setPlatformOption('charset', 'utf8mb4');
            $column->setPlatformOption('collation', 'utf8mb4_bin');
        }
    }
}
