<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application\Guard;

use Nowo\ShortLinkBundle\Domain\Model\GuardStage;
use Nowo\ShortLinkBundle\Domain\Model\TargetRefusal;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;
use Nowo\ShortLinkBundle\Domain\Model\TargetVerdict;
use Nowo\ShortLinkBundle\Domain\Port\TargetDenylistInterface;
use Nowo\ShortLinkBundle\Domain\Port\TargetGuardInterface;

/**
 * Asks the denylist port; a match is refused with `Denylisted`.
 */
final readonly class DenylistGuard implements TargetGuardInterface
{
    /**
     * Holds the denylist port.
     */
    public function __construct(private TargetDenylistInterface $denylist)
    {
    }

    /**
     * Refused when the port denies the target.
     */
    public function check(TargetUrl $target, GuardStage $stage): TargetVerdict
    {
        return $this->denylist->isDenied($target, $stage) ? TargetVerdict::refuse(TargetRefusal::Denylisted) : TargetVerdict::allow();
    }
}
