<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Guard;

use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Nowo\ShortLinkBundle\Domain\Model\GuardStage;
use Nowo\ShortLinkBundle\Domain\Model\TargetRefusal;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;
use Nowo\ShortLinkBundle\Domain\Model\TargetVerdict;
use Nowo\ShortLinkBundle\Domain\Port\TargetGuardInterface;

use function str_contains;

/**
 * OutboundUrlGuard as a target guard (contract §8): private, loopback, link-local, CGNAT and cloud-metadata targets
 * are refused, `allowPrivate` is always false. DNS runs only on Save / Recheck and only when `resolveDns` is true
 * (`target.save_resolve_dns`, and never with `guard: basic`); the redirect checks literals only (p95 budget).
 */
final readonly class OutboundUrlGuardBridge implements TargetGuardInterface
{
    /**
     * @param bool $resolveDns DNS check on Save / Recheck
     */
    public function __construct(private OutboundUrlGuard $guard, private bool $resolveDns)
    {
    }

    /**
     * Maps the OutboundUrlGuard decision to a verdict (any other refusal fails closed as `PrivateHost`; targets reach the
     * guard already normalised, so OutboundUrlGuard never answers "invalid" for them).
     */
    public function check(TargetUrl $target, GuardStage $stage): TargetVerdict
    {
        $decision = $this->guard->inspect($target->toString(), false, $this->resolveDns && $stage !== GuardStage::Redirect);
        if ($decision->isValid()) {
            return TargetVerdict::allow();
        }

        return TargetVerdict::refuse(match (true) {
            str_contains($decision->message, 'metadata')              => TargetRefusal::Metadata,
            str_contains($decision->message, 'could not be resolved') => TargetRefusal::Unresolvable,
            default                                                   => TargetRefusal::PrivateHost,
        });
    }
}
