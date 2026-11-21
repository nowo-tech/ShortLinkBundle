<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

/**
 * One page of source refs in ascending order, with the cursor of the next page (null on the last one).
 */
final readonly class SourceRefPage
{
    /**
     * @param list<string> $sourceRefs exact source refs, each once
     * @param string|null $next pass it as `$after` to read the next page
     */
    public function __construct(public array $sourceRefs, public ?string $next)
    {
    }
}
