<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ShortLinkBundle\Infrastructure\Doctrine\ShortLinkMetadataListener;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\DbalShortLinkStore;

/**
 * Schema of the `nowo_short_link` table (contract §5.1) for `doctrine:migrations:diff`: mapped with attributes
 * (`doctrine.mapping: attribute`) or by `Resources/config/doctrine-mapping/ShortLinkRecord.orm.xml` (default). The
 * bundle reads and writes the table through DBAL only ({@see DbalShortLinkStore}),
 * so this class is never hydrated and the host EntityManager is never flushed by the bundle. Table and index names get
 * `doctrine.table_prefix`, and the identifier columns a binary collation on MySQL / MariaDB, through
 * {@see ShortLinkMetadataListener}.
 *
 * @internal
 */
#[ORM\Entity]
#[ORM\Table(name: 'nowo_short_link')]
#[ORM\UniqueConstraint(name: 'uniq_short_link_code', columns: ['code'])]
#[ORM\Index(name: 'idx_short_link_tenant_list', columns: ['tenant_key', 'kind', 'deleted_at', 'created_at'])]
#[ORM\Index(name: 'idx_short_link_tenant_source', columns: ['tenant_key', 'kind', 'source_ref'])]
#[ORM\Index(name: 'idx_short_link_deleted', columns: ['deleted_at'])]
#[ORM\Index(name: 'idx_short_link_target_host', columns: ['target_host'])]
class ShortLinkRecord
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: Types::STRING, length: 36)]
    public string $id = '';

    #[ORM\Column(name: 'tenant_key', type: Types::STRING, length: 64)]
    public string $tenantKey = '';

    #[ORM\Column(name: 'code', type: Types::STRING, length: 32)]
    public string $code = '';

    #[ORM\Column(name: 'code_custom', type: Types::BOOLEAN)]
    public bool $codeCustom = false;

    #[ORM\Column(name: 'target_url', type: Types::STRING, length: 2048)]
    public string $targetUrl = '';

    #[ORM\Column(name: 'target_host', type: Types::STRING, length: 253)]
    public string $targetHost = '';

    #[ORM\Column(name: 'title', type: Types::STRING, length: 255, nullable: true)]
    public ?string $title = null;

    #[ORM\Column(name: 'kind', type: Types::STRING, length: 32)]
    public string $kind = 'manual';

    #[ORM\Column(name: 'source_ref', type: Types::STRING, length: 64, nullable: true)]
    public ?string $sourceRef = null;

    #[ORM\Column(name: 'source_label', type: Types::STRING, length: 128, nullable: true)]
    public ?string $sourceLabel = null;

    #[ORM\Column(name: 'source_position', type: Types::SMALLINT, nullable: true)]
    public ?int $sourcePosition = null;

    /** @var array<string, scalar|null>|null */
    #[ORM\Column(name: 'metadata', type: Types::JSON, nullable: true)]
    public ?array $metadata = null;

    #[ORM\Column(name: 'active', type: Types::BOOLEAN)]
    public bool $active = true;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?DateTimeImmutable $expiresAt = null;

    #[ORM\Column(name: 'disabled_reason', type: Types::STRING, length: 32, nullable: true)]
    public ?string $disabledReason = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    public ?DateTimeImmutable $updatedAt = null;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?DateTimeImmutable $deletedAt = null;

    #[ORM\Column(name: 'created_by', type: Types::STRING, length: 128, nullable: true)]
    public ?string $createdBy = null;

    #[ORM\Column(name: 'updated_by', type: Types::STRING, length: 128, nullable: true)]
    public ?string $updatedBy = null;
}
