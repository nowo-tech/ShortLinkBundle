<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Port;

use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;

/**
 * New link ids. Default: UUIDv7 through symfony/uid.
 */
interface ShortLinkIdGeneratorInterface
{
    /**
     * A new, time-ordered id.
     */
    public function generate(): ShortLinkId;
}
