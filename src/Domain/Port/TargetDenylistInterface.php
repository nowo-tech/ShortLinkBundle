<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Port;

use Nowo\ShortLinkBundle\Domain\Model\GuardStage;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;

/**
 * Host port: targets that must never be used (Beacon: an instance-settings host list). Default:
 * `target.denylist.hosts` (exact host or `.suffix`).
 */
interface TargetDenylistInterface
{
    /**
     * Must be cheap on {@see GuardStage::Redirect} (in memory or a host cache); may be slower on Save / Recheck.
     */
    public function isDenied(TargetUrl $target, GuardStage $stage): bool;
}
