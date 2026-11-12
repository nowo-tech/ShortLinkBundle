<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Port;

use Nowo\ShortLinkBundle\Domain\Model\UserAgentFamily;

/**
 * Classifies a User-Agent into a coarse family. Default: a bounded regex list over the first 512 bytes.
 */
interface UserAgentClassifierInterface
{
    /**
     * The family of this User-Agent (null or empty: `Unknown`).
     */
    public function classify(?string $userAgent): UserAgentFamily;
}
