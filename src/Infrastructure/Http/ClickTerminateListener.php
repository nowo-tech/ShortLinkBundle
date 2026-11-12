<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Http;

use Nowo\ShortLinkBundle\Application\Click\ClickPayload;
use Nowo\ShortLinkBundle\Application\Click\ClickRecorder;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkTargetRefusedEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Throwable;

use function is_array;
use function is_string;

/**
 * `kernel.terminate` listener of the redirect: after the response was sent, it dispatches the deferred
 * {@see ShortLinkTargetRefusedEvent} (with its warning) and, in `click_log.write: terminate`, writes the pending click.
 * Both live on the request only (worker mode: nothing is kept in the service) and are removed when handled. A throwing
 * listener or log handler is caught and logged with its class only: it can neither change the answer nor stop a
 * FrankenPHP worker.
 */
final readonly class ClickTerminateListener
{
    /** Request attribute holding `[ClickPayload, raw IP|null]`. */
    public const ATTRIBUTE = '_nowo_short_link_click';

    /** Request attribute holding the deferred {@see ShortLinkTargetRefusedEvent}. */
    public const REFUSED_ATTRIBUTE = '_nowo_short_link_refused';

    /**
     * Holds the recorder and the dispatcher.
     */
    public function __construct(
        private ClickRecorder $recorder,
        private EventDispatcherInterface $events,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Handles what the terminating request left, if anything.
     */
    public function onTerminate(TerminateEvent $event): void
    {
        $attributes = $event->getRequest()->attributes;
        $refused    = $attributes->get(self::REFUSED_ATTRIBUTE);
        $pending    = $attributes->get(self::ATTRIBUTE);
        $attributes->remove(self::REFUSED_ATTRIBUTE);
        $attributes->remove(self::ATTRIBUTE);

        if ($refused instanceof ShortLinkTargetRefusedEvent) {
            try {
                $this->logger?->warning('nowo_short_link: redirect of link {link} refused: {refusal}', ['link' => $refused->id?->value, 'refusal' => $refused->refusal->value]);
                $this->events->dispatch($refused);
            } catch (Throwable $exception) {
                $this->failed($exception);
            }
        }
        if (is_array($pending) && ($pending[0] ?? null) instanceof ClickPayload) {
            $this->recorder->record($pending[0], is_string($pending[1] ?? null) ? $pending[1] : null, true);
        }
    }

    private function failed(Throwable $exception): void
    {
        try {
            $this->logger?->warning('nowo_short_link: a listener of the refused-target event failed: {class}', ['class' => $exception::class]);
        } catch (Throwable) {
            // A failing log handler must not stop the worker either.
        }
    }
}
