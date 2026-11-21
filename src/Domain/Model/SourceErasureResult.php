<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

/**
 * What one {@see \Nowo\ShortLinkBundle\Domain\Service\ShortLinkRetentionInterface::eraseSourcesCreatedBefore()} call
 * erased. Every batch it counts is committed.
 */
final readonly class SourceErasureResult
{
    /**
     * @param int $sources sources whose links this call tombstoned
     * @param int $rows link rows blanked plus click and rollup rows deleted
     * @param int $batches committed batches
     * @param bool $complete true when no source older than the bound was left; false when the call stopped at
     *                       `$maxBatches` (call it again to go on)
     */
    public function __construct(public int $sources, public int $rows, public int $batches, public bool $complete)
    {
    }
}
