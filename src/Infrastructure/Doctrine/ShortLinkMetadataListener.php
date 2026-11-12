<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use LogicException;
use Nowo\ShortLinkBundle\Infrastructure\Doctrine\Entity\ShortLinkRecord;
use Throwable;

use function in_array;
use function is_string;

/**
 * Doctrine `loadClassMetadata` listener of {@see ShortLinkRecord}: prefixes the table and its index names with
 * `doctrine.table_prefix` (REQ-ORM-002) and gives the identifier and code columns `utf8mb4_bin` on MySQL / MariaDB
 * (contract F3, rule LQ: `aB3xY9k` and `AB3XY9K` are two codes).
 *
 * @internal
 */
final readonly class ShortLinkMetadataListener
{
    /** Unprefixed table name. */
    public const TABLE = 'nowo_short_link';

    /** Columns compared exactly. */
    public const BINARY_COLUMNS = ['id', 'tenant_key', 'code', 'source_ref', 'created_by', 'updated_by'];

    /**
     * @param string $tablePrefix `doctrine.table_prefix`, may be empty
     */
    public function __construct(private string $tablePrefix = '')
    {
    }

    /**
     * Doctrine `loadClassMetadata` listener.
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        if ($metadata->getName() !== ShortLinkRecord::class) {
            return;
        }

        $table = ['name' => $this->tablePrefix . self::TABLE];
        foreach (['indexes', 'uniqueConstraints'] as $key) {
            $renamed = [];
            foreach ($metadata->table[$key] ?? [] as $name => $definition) {
                $renamed[is_string($name) ? $this->tablePrefix . $name : $name] = $definition;
            }
            $table[$key] = $renamed;
        }
        $metadata->setPrimaryTable($table);

        try {
            $platform = $args->getObjectManager()->getConnection()->getDatabasePlatform();
        } catch (Throwable $exception) {
            throw new LogicException('nowo_short_link needs the database platform to map the code and identifier columns with a binary collation. Set doctrine.dbal.server_version (or make the database reachable) before warming up the cache.', 0, $exception);
        }
        if (!$platform instanceof AbstractMySQLPlatform) {
            return;
        }
        foreach ($metadata->fieldMappings as $field => $mapping) {
            if (in_array($mapping->columnName, self::BINARY_COLUMNS, true)) {
                $metadata->fieldMappings[$field]->options = ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin'] + ($mapping->options ?? []);
            }
        }
    }
}
