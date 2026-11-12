<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Fixtures;

use RuntimeException;

/**
 * Event listener that throws while `failing` is true (test-only state).
 */
final class ThrowingListener
{
    public bool $failing = false;

    public int $calls = 0;

    public function onEvent(): void
    {
        ++$this->calls;
        if ($this->failing) {
            throw new RuntimeException('listener down');
        }
    }
}
