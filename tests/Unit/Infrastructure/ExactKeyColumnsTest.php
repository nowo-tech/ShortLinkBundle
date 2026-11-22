<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit\Infrastructure;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MigrationsKitContracts\Collation\ExactKeyColumn;
use Nowo\ShortLinkBundle\Infrastructure\Doctrine\ClickTablesSchemaListener;
use Nowo\ShortLinkBundle\Infrastructure\Doctrine\ShortLinkExactKeyColumns;
use Nowo\ShortLinkBundle\Infrastructure\Doctrine\ShortLinkMetadataListener;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\ClickTables;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;

/**
 * The REQ-SEC-006 declaration: the list itself, and that it cannot drift from the shipped schema.
 */
final class ExactKeyColumnsTest extends TestCase
{
    public function testTheDeclarationNamesEveryIdentityColumnOfTheThreeTables(): void
    {
        $columns = (new ShortLinkExactKeyColumns())->exactKeyColumns();

        self::assertSame([
            'nowo_short_link.id',
            'nowo_short_link.tenant_key',
            'nowo_short_link.code',
            'nowo_short_link.source_ref',
            'nowo_short_link.created_by',
            'nowo_short_link.updated_by',
            'nowo_short_link_click.link_id',
            'nowo_short_link_click.tenant_key',
            'nowo_short_link_click_daily.link_id',
            'nowo_short_link_click_daily.tenant_key',
            'nowo_short_link_click_daily.value',
        ], array_map(static fn (ExactKeyColumn $c): string => $c->key(), $columns));

        foreach ($columns as $column) {
            self::assertSame('nowo-tech/short-link-bundle', $column->owner);
            self::assertNotSame('', $column->purpose, $column->describe() . ' is declared without saying what the key is');
            self::assertTrue($column->active, 'the click tables are on the link connection by default');
            self::assertNull($column->condition);
        }
    }

    public function testTheTableNamesFollowTheConfiguredPrefixAndNeverALiteral(): void
    {
        $tables = new ClickTables($this->createStub(ManagerRegistry::class), null, null, 'app_');
        $named  = array_values(array_unique(array_map(static fn (ExactKeyColumn $c): string => $c->table, (new ShortLinkExactKeyColumns('app_'))->exactKeyColumns())));

        // The same names the queries use (ClickTables), built from the same prefix: a prefixed host gets no false
        // failure, which a literal table name would guarantee.
        self::assertSame([$tables->links, $tables->clicks, $tables->daily], $named);
        self::assertSame('app_nowo_short_link', $tables->links);
    }

    public function testClickColumnsAreSkippedNotFailedWhenTheClickTablesAreOnAnotherConnection(): void
    {
        $columns = (new ShortLinkExactKeyColumns('', false))->exactKeyColumns();
        $link    = array_filter($columns, static fn (ExactKeyColumn $c): bool => $c->table === 'nowo_short_link');
        $clicks  = array_filter($columns, static fn (ExactKeyColumn $c): bool => $c->table !== 'nowo_short_link');

        self::assertCount(6, $link);
        self::assertCount(5, $clicks);
        foreach ($link as $column) {
            self::assertTrue($column->active, 'the link table is always on the checked connection');
        }
        foreach ($clicks as $column) {
            self::assertFalse($column->active);
            self::assertSame(ShortLinkExactKeyColumns::CLICK_CONDITION, $column->condition);
        }
    }

    public function testTheDeclarationCoversExactlyTheColumnsTheSchemaMakesBinary(): void
    {
        // Link table: the mapping listener's own list, so a new binary column cannot be left undeclared.
        self::assertSame(ShortLinkMetadataListener::BINARY_COLUMNS, array_keys(ShortLinkExactKeyColumns::LINK_PURPOSES));

        // Click tables: the columns the schema listener really puts on the binary collation.
        $schema = new Schema();
        (new ClickTablesSchemaListener())->addTables($schema, 'utf8mb4_0900_bin');
        foreach ([
            ClickTablesSchemaListener::CLICK_TABLE      => ClickTablesSchemaListener::CLICK_BINARY_COLUMNS,
            ClickTablesSchemaListener::DAILY_TABLE      => ClickTablesSchemaListener::DAILY_BINARY_COLUMNS,
            ClickTablesSchemaListener::ROLLUP_DAY_TABLE => [],
        ] as $table => $declared) {
            $binary = [];
            foreach ($schema->getTable($table)->getColumns() as $column) {
                if ($column->hasPlatformOption('collation')) {
                    $binary[] = $column->getName();
                }
            }
            self::assertSame($declared, $binary, $table . ': declared binary columns and schema disagree');
        }
    }
}
