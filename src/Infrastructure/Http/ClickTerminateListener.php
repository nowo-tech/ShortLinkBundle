<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Http;

use Nowo\ShortLinkBundle\Application\Click\ClickPayload;
use Nowo\ShortLinkBundle\Application\Click\ClickRecorder;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

use function is_array;
use function is_string;

/**
 * `kernel.terminate` listener of `click_log.write: terminate`: writes the click the redirect left on its request,
 * after the response was sent. The payload lives on the request only (worker mode: nothing is kept in the service).
 */
final readonly class ClickTerminateListener
{
    /** Request attribute holding `[ClickPayload, raw IP|null]`. */
    public const ATTRIBUTE = '_nowo_short_link_click';

    /**
     * Holds the recorder.
     */
    public function __construct(private ClickRecorder $recorder)
    {
    }

    /**
     * Records the pending click of the terminating request, if any.
     */
    public function onTerminate(TerminateEvent $event): void
    {
        $pending = $event->getRequest()->attributes->get(self::ATTRIBUTE);
        $event->getRequest()->attributes->remove(self::ATTRIBUTE);
        if (is_array($pending) && ($pending[0] ?? null) instanceof ClickPayload) {
            $this->recorder->record($pending[0], is_string($pending[1] ?? null) ? $pending[1] : null);
        }
    }
}
