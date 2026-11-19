<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Fixtures;

/**
 * Transaction nesting level shared by a mocked DBAL connection and the unit under test (audit UY-1 tests).
 */
final class NestingState
{
    public function __construct(public int $level, public bool $reopenFails = false)
    {
    }
}
