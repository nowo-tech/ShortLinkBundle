<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application\Click;

use Nowo\ShortLinkBundle\Application\Store\ClickStoreInterface;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkClickedEvent;
use Nowo\ShortLinkBundle\Domain\Model\CountryCode;
use Nowo\ShortLinkBundle\Domain\Port\ClickCountryResolverInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * Writes one click row and dispatches {@see ShortLinkClickedEvent} (contract §7.3). `Sec-GPC: 1` with `honour_gpc`
 * keeps the time, the link and the machine flag only; `click_log.store` switches columns off. The raw IP only reaches
 * the country resolver (never stored, never logged). A broken click table or resolver never breaks the redirect: the
 * failure is logged with its exception class only. Stateless (worker mode).
 */
final readonly class ClickRecorder
{
    /**
     * @param array{ip_prefix: bool, user_agent_family: bool, referrer_host: bool, country: bool} $store `click_log.store`
     */
    public function __construct(
        private ClickStoreInterface $clicks,
        private ClickCountryResolverInterface $countries,
        private EventDispatcherInterface $events,
        private array $store = ['ip_prefix' => true, 'user_agent_family' => true, 'referrer_host' => true, 'country' => true],
        private bool $honourGpc = true,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Records the click; `$clientIp` is the raw IP for the country resolver only (null in Messenger mode).
     */
    public function record(ClickPayload $click, #[SensitiveParameter] ?string $clientIp = null): void
    {
        $private = $click->gpc && $this->honourGpc;
        $ua      = !$private && $this->store['user_agent_family'] ? $click->uaFamily : null;
        $country = !$private && $this->store['country'] && $clientIp !== null ? $this->country($clientIp) : null;
        try {
            $this->clicks->insert(
                $click->linkId,
                $click->tenant,
                $click->clickedAt,
                !$private && $this->store['ip_prefix'] ? $click->ipPrefix?->value : null,
                $ua?->value,
                !$private && $this->store['referrer_host'] ? $click->referrerHost?->value : null,
                $country?->value,
                $click->isMachine(),
            );
        } catch (Throwable $exception) {
            $this->logger?->warning('nowo_short_link: click of link {link} not recorded: {class}', ['link' => $click->linkId->value, 'class' => $exception::class]);

            return;
        }
        $this->events->dispatch(new ShortLinkClickedEvent($click->linkId, $click->tenant, $click->kind, $click->sourceRef, $click->clickedAt, $click->isMachine(), $ua, $country));
    }

    private function country(#[SensitiveParameter] string $clientIp): ?CountryCode
    {
        try {
            return $this->countries->countryFor($clientIp);
        } catch (Throwable $exception) {
            $this->logger?->warning('nowo_short_link: country resolver failed: {class}', ['class' => $exception::class]);

            return null;
        }
    }
}
