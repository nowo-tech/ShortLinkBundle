<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Doctrine;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Nowo\ShortLinkBundle\Infrastructure\Doctrine\Entity\ShortLinkRecord;

/**
 * Doctrine `postGenerateSchema` listener: adds the DBAL click tables (contract §5.2, no ORM entity) to the schema of
 * the entity manager that maps {@see ShortLinkRecord}, so `doctrine:migrations:diff` creates them with the link table.
 * Names get `doctrine.table_prefix`; identifier columns get a binary NO PAD collation on MySQL / MariaDB
 * ({@see BinaryCollation}).
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
     * Raw-click columns compared exactly, never sorted or matched case-insensitively (declared to the collation gate
     * by {@see ShortLinkExactKeyColumns}).
     *
     * @var list<string>
     */
    public const CLICK_BINARY_COLUMNS = ['link_id', 'tenant_key'];

    /**
     * Daily-rollup columns compared exactly: the text parts of its primary key and the tenant key.
     *
     * @var list<string>
     */
    public const DAILY_BINARY_COLUMNS = ['link_id', 'tenant_key', 'value'];

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
        $this->addTables($args->getSchema(), BinaryCollation::for($manager->getConnection()->getDatabasePlatform()));
    }

    /**
     * Adds the three click tables to a schema (also used by tests and hosts that build the schema themselves).
     */
    public function addTables(Schema $schema, ?string $collation): void
    {
        $p = $this->tablePrefix;

        $click = $schema->createTable($p . self::CLICK_TABLE);
        $click->addColumn('id', Types::BIGINT, ['autoincrement' => true]);
        $click->addColumn('link_id', Types::STRING, ['length' => 36]);
        $click->addColumn('tenant_key', Types::STRING, ['length' => 64]);
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
        $this->binary($click, self::CLICK_BINARY_COLUMNS, $collation);

        $daily = $schema->createTable($p . self::DAILY_TABLE);
        $daily->addColumn('link_id', Types::STRING, ['length' => 36]);
        $daily->addColumn('tenant_key', Types::STRING, ['length' => 64]);
        $daily->addColumn('day', Types::DATE_IMMUTABLE);
        $daily->addColumn('dimension', Types::STRING, ['length' => 16]);
        $daily->addColumn('value', Types::STRING, ['length' => 253, 'default' => '']);
        $daily->addColumn('clicks', Types::INTEGER, ['default' => 0]);
        $daily->addColumn('machine_clicks', Types::INTEGER, ['default' => 0]);
        $daily->setPrimaryKey(['link_id', 'day', 'dimension', 'value']);
        $daily->addIndex(['tenant_key', 'day'], $p . 'idx_short_link_daily_tenant');
        $daily->addIndex(['day'], $p . 'idx_short_link_daily_day');
        $this->binary($daily, self::DAILY_BINARY_COLUMNS, $collation);

        $days = $schema->createTable($p . self::ROLLUP_DAY_TABLE);
        $days->addColumn('day', Types::DATE_IMMUTABLE);
        $days->addColumn('rolled_at', Types::DATETIME_IMMUTABLE);
        $days->setPrimaryKey(['day']);
    }

    /**
     * @param list<string> $columns
     */
    private function binary(Table $table, array $columns, ?string $collation): void
    {
        if ($collation === null) {
            return;
        }
        foreach ($columns as $name) {
            $column = $table->getColumn($name);
            $column->setPlatformOption('charset', 'utf8mb4');
            $column->setPlatformOption('collation', $collation);
        }
    }
}
