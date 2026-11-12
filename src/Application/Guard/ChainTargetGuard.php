<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application\Guard;

use Nowo\ShortLinkBundle\Domain\Model\GuardStage;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;
use Nowo\ShortLinkBundle\Domain\Model\TargetVerdict;
use Nowo\ShortLinkBundle\Domain\Port\TargetGuardInterface;

/**
 * Runs guards in order; the first refusal answers (default chain: self chain, OutboundUrlGuard, denylist).
 */
final readonly class ChainTargetGuard implements TargetGuardInterface
{
    /**
     * @param list<TargetGuardInterface> $guards
     */
    public function __construct(private array $guards)
    {
    }

    /**
     * Allowed only when every guard allows.
     */
    public function check(TargetUrl $target, GuardStage $stage): TargetVerdict
    {
        foreach ($this->guards as $guard) {
            $verdict = $guard->check($target, $stage);
            if (!$verdict->allowed) {
                return $verdict;
            }
        }

        return TargetVerdict::allow();
    }
}
