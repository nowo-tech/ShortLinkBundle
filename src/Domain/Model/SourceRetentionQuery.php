<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

use DateTimeImmutable;
use Nowo\ShortLinkBundle\Domain\Exception\InvalidShortLinkArgumentException;

/**
 * Sources (the `ref` of a {@see LinkSource}, e.g. an e-mail send id) of one tenant and kind that have a link created
 * before a moment, read or erased {@see self::$batchSize} at a time
 * ({@see \Nowo\ShortLinkBundle\Domain\Service\ShortLinkRetentionInterface}).
 */
final readonly class SourceRetentionQuery
{
    /** Default page and batch size. */
    public const DEFAULT_BATCH_SIZE = 500;

    /** Largest page and batch size. */
    public const MAX_BATCH_SIZE = 1000;

    /**
     * @param DateTimeImmutable $before exclusive bound on the link's creation time (compared in UTC)
     * @param int $batchSize 1 to {@see self::MAX_BATCH_SIZE} sources per page or erased batch
     *
     * @throws InvalidShortLinkArgumentException when the batch size is out of range
     */
    public function __construct(
        public TenantKey $tenant,
        public LinkKind $kind,
        public DateTimeImmutable $before,
        public int $batchSize = self::DEFAULT_BATCH_SIZE,
    ) {
        if ($batchSize < 1 || $batchSize > self::MAX_BATCH_SIZE) {
            throw new InvalidShortLinkArgumentException('A batch has 1 to 1000 sources.');
        }
    }
}
