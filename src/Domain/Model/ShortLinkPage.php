<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

/**
 * One page of links, newest first, with the cursor of the next page (null on the last one).
 */
final readonly class ShortLinkPage
{
    /**
     * @param list<ShortLinkView> $items
     */
    public function __construct(public array $items, public ?ShortLinkId $next)
    {
    }
}
