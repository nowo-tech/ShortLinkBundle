<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;

/**
 * Collation of the identifier columns (code, tenant, ids, source ref, actors) on MySQL / MariaDB: binary (exact case,
 * contract F3 / rule LQ) and NO PAD where the server has one, so trailing spaces are significant too (`'u1 '` is not
 * `'u1'`): `utf8mb4_0900_bin` on MySQL 8 (DBAL `MySQL80Platform`; MySQL 8.0.17+), `utf8mb4_nopad_bin` on MariaDB, and
 * `utf8mb4_bin` (PAD SPACE) on older MySQL. Other platforms compare exactly already (null).
 *
 * @internal
 */
final class BinaryCollation
{
    /** MySQL 8 NO PAD binary collation. */
    public const MYSQL_8 = 'utf8mb4_0900_bin';

    /** MariaDB NO PAD binary collation. */
    public const MARIADB = 'utf8mb4_nopad_bin';

    /** PAD SPACE binary collation of older MySQL servers. */
    public const FALLBACK = 'utf8mb4_bin';

    /**
     * The collation for this platform, or null when the platform is not MySQL / MariaDB.
     */
    public static function for(AbstractPlatform $platform): ?string
    {
        return match (true) {
            $platform instanceof MariaDBPlatform       => self::MARIADB,
            $platform instanceof MySQL80Platform       => self::MYSQL_8,
            $platform instanceof AbstractMySQLPlatform => self::FALLBACK,
            default                                    => null,
        };
    }
}
