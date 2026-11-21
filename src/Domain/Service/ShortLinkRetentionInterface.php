<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Service;

use Nowo\ShortLinkBundle\Domain\Exception\InvalidShortLinkArgumentException;
use Nowo\ShortLinkBundle\Domain\Model\SourceErasureResult;
use Nowo\ShortLinkBundle\Domain\Model\SourceRefPage;
use Nowo\ShortLinkBundle\Domain\Model\SourceRetentionQuery;
use Nowo\TransactionContract\TransientStorageFailure;

/**
 * Maintenance port for source retention: the generated links of a source (e.g. the click links of an e-mail send)
 * expire with the source. A source is "older than the bound" when one of its links was created before
 * {@see SourceRetentionQuery::$before}; deleted links count (their clicks are still stored), erased tombstones do not
 * (they no longer carry the source). A system API: no access check. Stateless (worker mode); it never touches an
 * EntityManager, only the bundle's DBAL connection.
 */
interface ShortLinkRetentionInterface
{
    /**
     * One page of the sources older than the bound, in ascending ref order (keyset paging).
     *
     * @param string|null $after the `next` cursor of the previous page
     *
     * @throws InvalidShortLinkArgumentException when the cursor is not a source ref
     */
    public function sourcesCreatedBefore(SourceRetentionQuery $query, ?string $after = null): SourceRefPage;

    /**
     * Erases the sources older than the bound like {@see ShortLinkEraserInterface::eraseSource()}: every link of each
     * source (a newer link of an old source too) becomes a blanked tombstone, and its clicks and daily rollups are
     * deleted; `batchSize` sources per transaction, until none is left or `$maxBatches` batches are committed.
     *
     * Idempotent: an erased source no longer matches, so a re-run (after a failure, or two runs at once) erases only
     * what is left. Each batch locks its links in id order before writing, so a concurrent run waits and then skips
     * them. Refused inside an open transaction on the bundle's connection: every batch must commit on its own.
     *
     * A lock conflict (REQ-RUNTIME-003 rule 6) is thrown as a {@see TransientStorageFailure}. Nothing of the call is
     * committed when it is thrown for the first batch (`CONFLICT_001`: retry the call). When earlier batches are
     * committed it is a `Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict` with `committed` true
     * (`ConflictCode::forFailure()` answers `CONFLICT_002`): the committed batches stay erased, and calling again
     * finishes the rest.
     *
     * @param int|null $maxBatches at most this many batches (1 or more); null = until done
     *
     * @throws InvalidShortLinkArgumentException when `$maxBatches` is below 1
     * @throws TransientStorageFailure on a lock conflict
     */
    public function eraseSourcesCreatedBefore(SourceRetentionQuery $query, ?int $maxBatches = null): SourceErasureResult;
}
