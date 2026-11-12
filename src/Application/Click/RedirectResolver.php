<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application\Click;

use DateTimeImmutable;
use Nowo\ShortLinkBundle\Application\Store\ShortLinkStoreInterface;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkTargetRefusedEvent;
use Nowo\ShortLinkBundle\Domain\Model\GuardStage;
use Nowo\ShortLinkBundle\Domain\Model\ResolvedLink;
use Nowo\ShortLinkBundle\Domain\Model\TargetRefusal;
use Nowo\ShortLinkBundle\Domain\Port\TargetGuardInterface;
use Psr\Clock\ClockInterface;

/**
 * Steps 3 to 5 of the redirect (contract §7.1): one lookup by code (exact, state decided from the raw row: deleted,
 * disabled and expired rows are never hydrated) and the redirect guard (no DNS). Every failure answers no link after
 * the same single query, so the caller answers one neutral 404 for every reason. A refused target is returned as a
 * {@see ShortLinkTargetRefusedEvent} the caller dispatches after the response, so neither a listener nor a log
 * handler can change the answer or its timing. Stateless (worker mode).
 */
final readonly class RedirectResolver
{
    /**
     * Wires the store, the guard and the clock.
     */
    public function __construct(
        private ShortLinkStoreInterface $store,
        private TargetGuardInterface $guard,
        private ?ClockInterface $clock = null,
    ) {
    }

    /**
     * The decision for this exact code: the active, allowed link, or no link (with the refusal event when the guard
     * refused the target).
     */
    public function resolve(string $code): RedirectDecision
    {
        $link = $this->store->findActiveByCode($code, $this->clock?->now() ?? new DateTimeImmutable());
        if (!$link instanceof ResolvedLink) {
            return new RedirectDecision(null, null);
        }
        $verdict = $this->guard->check($link->target, GuardStage::Redirect);
        if ($verdict->refusal instanceof TargetRefusal) {
            return new RedirectDecision(null, new ShortLinkTargetRefusedEvent($link->tenant, GuardStage::Redirect, $verdict->refusal, $link->target->host(), $link->id));
        }

        return new RedirectDecision($link, null);
    }
}
