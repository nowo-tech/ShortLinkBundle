<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Persistence;

use Symfony\Component\HttpKernel\Event\FinishRequestEvent;

/**
 * Clears the {@see AfterCommitQueue} at every request and message boundary (`kernel.finish_request` of the main
 * request, `kernel.terminate`, `console.terminate`, Messenger `WorkerMessageHandledEvent` / `WorkerMessageFailedEvent`),
 * so worker mode without the services resetter never carries held work into the next request.
 *
 * @internal
 */
final readonly class AfterCommitQueueResetListener
{
    /**
     * Holds the queue.
     */
    public function __construct(private AfterCommitQueue $queue)
    {
    }

    /**
     * `kernel.finish_request`: main request only (a sub-request may finish inside the main request's transaction).
     */
    public function onFinishRequest(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->queue->reset();
        }
    }

    /**
     * `kernel.terminate`, `console.terminate` and the Messenger worker events.
     */
    public function onBoundary(): void
    {
        $this->queue->reset();
    }
}
