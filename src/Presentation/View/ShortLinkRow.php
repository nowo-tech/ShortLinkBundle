<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Presentation\View;

use Nowo\ShortLinkBundle\Domain\Model\ShortLinkView;

/**
 * One row of the list page: the link, its clicks of the last 30 days (people and machine traffic apart) and the URLs
 * of its statistics and edit pages (the host's tenant route parameter kept).
 */
final readonly class ShortLinkRow
{
    /**
     * Holds the values.
     */
    public function __construct(
        public ShortLinkView $link,
        public int $clicks,
        public int $machine,
        public string $stats,
        public string $edit,
    ) {
    }
}
