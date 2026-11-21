<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

use Nowo\ShortLinkBundle\Domain\Service\ShortLinkRetentionInterface;

/**
 * What one {@see ShortLinkRetentionInterface::eraseSourcesCreatedBefore()} call
 * erased. Every batch it counts is committed.
 */
final readonly class SourceErasureResult
{
    /**
     * @param int $sources sources whose links this call tombstoned
     * @param int $rows link rows blanked plus click and rollup rows deleted
     * @param int $batches committed batches that erased at least one source (a batch whose listed sources another
     *                     run erased first is not counted)
     * @param bool $complete true when no source older than the bound was left; false when the call stopped at
     *                       `$maxBatches`, or when the sources it listed were erased by another run before its batch
     *                       locked them (it stops rather than spin). Either way, call it again: it goes on, or answers
     *                       complete when nothing is left
     */
    public function __construct(public int $sources, public int $rows, public int $batches, public bool $complete)
    {
    }
}
