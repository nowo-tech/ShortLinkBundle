<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Doctrine;

use Nowo\MigrationsKitContracts\Collation\ExactKeyColumn;
use Nowo\MigrationsKitContracts\Collation\ExactKeyColumnsInterface;

/**
 * Declares the bundle's identity and access-key columns (REQ-SEC-006), so a host can assert they really are in a
 * binary NO PAD collation.
 *
 * The bundle already puts them there itself ({@see ShortLinkMetadataListener}, {@see ClickTablesSchemaListener},
 * {@see BinaryCollation}), but only for a schema it generates: a table created by an older release, by a hand-written
 * migration or by a host that builds the schema itself can still carry the server default
 * (`utf8mb4_0900_ai_ci`: case-, accent-insensitive and PAD SPACE), where `aB3xY9k` and `AB3XY9K` are one code and
 * `u1 ` is `u1`. The declaration is what lets `nowo:migrations:check-key-collations` see those columns at all, and it
 * exempts them from the uniform collation gate at the same time, so a host never has to excuse them by hand
 * (`collation_check.ignore_columns` / `ignore_tables`).
 *
 * Discovery is automatic: the service is autoconfigured, so MigrationsKit tags it and an installed host registers
 * nothing. Table names are built from the same `doctrine.table_prefix` and the same constants the schema is built
 * from, never from a literal — a host with a prefix would otherwise get a wall of false failures.
 *
 * Stateless and free of per-request state (REQ-RUNTIME-002): the list is computed on each call from constructor
 * arguments only.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ShortLinkExactKeyColumns implements ExactKeyColumnsInterface
{
    /** Package that owns the tables, as the report names it. */
    public const OWNER = 'nowo-tech/short-link-bundle';

    /**
     * Why the link-table columns are compared exactly, by column name. The keys are
     * {@see ShortLinkMetadataListener::BINARY_COLUMNS}: declaration and mapping cannot drift apart.
     *
     * @var array<string, string>
     */
    public const LINK_PURPOSES = [
        'id'         => 'link id (UUIDv7), the row key of every read, update and delete',
        'tenant_key' => 'host tenant key: the access boundary every query filters on and re-checks in PHP',
        'code'       => 'public short code, unique and case-sensitive (contract F3): the redirect lookup key',
        'source_ref' => 'host record reference of a generated link (one live link per source, UK-SL-2)',
        'created_by' => 'host user id of the creator',
        'updated_by' => 'host user id of the last editor',
    ];

    /**
     * Why the click-table columns are compared exactly, by column name (both click tables).
     *
     * @var array<string, string>
     */
    public const CLICK_PURPOSES = [
        'link_id'    => 'link id the click belongs to (no foreign key: the tables can live on another connection)',
        'tenant_key' => 'host tenant key of the click: the access boundary of every statistics query',
        'value'      => 'dimension value (referrer host, country, user-agent family), part of the rollup primary key',
    ];

    /**
     * The configuration that puts the click tables on the checked connection, as the skipped findings state it.
     */
    public const CLICK_CONDITION = 'nowo_short_link.doctrine.connection: null (otherwise the click tables are on another connection)';

    /**
     * @param string $tablePrefix `nowo_short_link.doctrine.table_prefix` (REQ-ORM-002), as the schema uses it
     * @param bool $clickTablesOnLinkConnection whether `doctrine.connection` is null, so the click tables are on the
     *                                          same connection as the link table; when a host gives them their own
     *                                          connection their columns are not in this database and are declared
     *                                          inactive (reported as skipped, never failed)
     */
    public function __construct(
        private string $tablePrefix = '',
        private bool $clickTablesOnLinkConnection = true,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function exactKeyColumns(): array
    {
        $columns = [];
        foreach (self::LINK_PURPOSES as $column => $purpose) {
            $columns[] = new ExactKeyColumn($this->tablePrefix . ShortLinkMetadataListener::TABLE, $column, self::OWNER, $purpose);
        }

        $active    = $this->clickTablesOnLinkConnection;
        $condition = $active ? null : self::CLICK_CONDITION;
        foreach ([
            ClickTablesSchemaListener::CLICK_TABLE => ClickTablesSchemaListener::CLICK_BINARY_COLUMNS,
            ClickTablesSchemaListener::DAILY_TABLE => ClickTablesSchemaListener::DAILY_BINARY_COLUMNS,
        ] as $table => $names) {
            foreach ($names as $column) {
                $columns[] = new ExactKeyColumn($this->tablePrefix . $table, $column, self::OWNER, self::CLICK_PURPOSES[$column], $condition, $active);
            }
        }

        return $columns;
    }
}
