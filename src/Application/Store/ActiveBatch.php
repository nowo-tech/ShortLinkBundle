<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application\Store;

/**
 * One keyset batch of active links for the recheck: the readable links, the id of the last raw row (the cursor, even
 * when that row was unreadable) and how many raw rows were read (the run continues while it equals the batch size).
 *
 * @internal
 */
final readonly class ActiveBatch
{
    /**
     * @param list<StoredLink> $links
     */
    public function __construct(public array $links, public ?string $cursor, public int $rawCount)
    {
    }
}
