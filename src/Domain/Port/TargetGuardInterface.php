<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Port;

use Nowo\ShortLinkBundle\Domain\Model\GuardStage;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;
use Nowo\ShortLinkBundle\Domain\Model\TargetVerdict;

/**
 * Decides whether a target may be saved or followed. Default: the chain of the self-chain check, OutboundUrlGuard
 * (DNS on save / recheck only, never private) and the denylist port.
 */
interface TargetGuardInterface
{
    /**
     * Checks a normalised target at a stage.
     */
    public function check(TargetUrl $target, GuardStage $stage): TargetVerdict;
}
