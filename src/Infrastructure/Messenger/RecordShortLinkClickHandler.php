<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Messenger;

use Nowo\ShortLinkBundle\Application\Click\ClickRecorder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Writes the click of a {@see RecordShortLinkClick} message (no country: the raw IP never travels in a message).
 */
#[AsMessageHandler]
final readonly class RecordShortLinkClickHandler
{
    /**
     * Holds the recorder.
     */
    public function __construct(private ClickRecorder $recorder)
    {
    }

    /**
     * Records the click.
     */
    public function __invoke(RecordShortLinkClick $message): void
    {
        $this->recorder->record($message->click);
    }
}
