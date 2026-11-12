<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

use Nowo\ShortLinkBundle\Domain\Exception\InvalidShortLinkArgumentException;

use function mb_strlen;

/**
 * Filters and keyset position of a list page: newest first; `after` is the last id of the previous page.
 */
final readonly class ShortLinkListQuery
{
    /** Cleaned search text (code, title or target host), or null. */
    public ?string $search;

    /**
     * @param LinkKind|null $kind only this kind; null = every kind shown in the UI
     * @param ShortLinkState|null $state only this state (`Deleted` is never listed)
     * @param string|null $search substring of the code, title or target host (≤ 100 characters)
     * @param int $limit 1 to 100
     * @param ShortLinkId|null $after keyset cursor
     *
     * @throws InvalidShortLinkArgumentException when the limit or the search breaks its rule
     */
    public function __construct(
        public ?LinkKind $kind = null,
        public ?ShortLinkState $state = null,
        ?string $search = null,
        public int $limit = 25,
        public ?ShortLinkId $after = null,
    ) {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidShortLinkArgumentException('A page has 1 to 100 links.');
        }
        $search = $search === null ? null : Text::clean($search);
        if ($search !== null && mb_strlen($search) > 100) {
            throw new InvalidShortLinkArgumentException('A search has at most 100 characters.');
        }
        $this->search = $search === '' ? null : $search;
    }
}
