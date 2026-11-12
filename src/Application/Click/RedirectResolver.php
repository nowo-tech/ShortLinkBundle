<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application\Click;

use DateTimeImmutable;
use Nowo\ShortLinkBundle\Application\Store\ShortLinkStoreInterface;
use Nowo\ShortLinkBundle\Application\Store\StoredLink;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkTargetRefusedEvent;
use Nowo\ShortLinkBundle\Domain\Model\GuardStage;
use Nowo\ShortLinkBundle\Domain\Model\ResolvedLink;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkState;
use Nowo\ShortLinkBundle\Domain\Model\TargetRefusal;
use Nowo\ShortLinkBundle\Domain\Port\TargetGuardInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Steps 3 to 5 of the redirect (contract §7.1): one lookup by code with an exact re-check, the state (deleted,
 * disabled, expired) and the redirect guard (no DNS). Every failure answers null, after the same single query, so the
 * caller answers one neutral 404 for every reason. A refused target raises {@see ShortLinkTargetRefusedEvent} and a
 * warning with the link id and the refusal only. Stateless (worker mode).
 */
final readonly class RedirectResolver
{
    /**
     * Wires the store, the guard and the clock.
     */
    public function __construct(
        private ShortLinkStoreInterface $store,
        private TargetGuardInterface $guard,
        private EventDispatcherInterface $events,
        private ?ClockInterface $clock = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The active, allowed link of this exact code, or null.
     */
    public function resolve(string $code): ?ResolvedLink
    {
        $link = $this->store->findByCode($code);
        if (!$link instanceof StoredLink || $link->stateAt($this->clock?->now() ?? new DateTimeImmutable()) !== ShortLinkState::Active) {
            return null;
        }
        $verdict = $this->guard->check($link->target, GuardStage::Redirect);
        if ($verdict->refusal instanceof TargetRefusal) {
            $this->logger?->warning('nowo_short_link: redirect of link {link} refused: {refusal}', ['link' => $link->id->value, 'refusal' => $verdict->refusal->value]);
            $this->events->dispatch(new ShortLinkTargetRefusedEvent($link->tenant, GuardStage::Redirect, $verdict->refusal, $link->target->host(), $link->id));

            return null;
        }

        return new ResolvedLink($link->id, $link->tenant, $link->code, $link->target, $link->kind, ShortLinkState::Active, $link->source?->ref);
    }
}
