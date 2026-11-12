<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Messenger;

use Nowo\ShortLinkBundle\Application\Click\ClickPayload;

/**
 * Messenger message of `click_log.write: messenger` (REQ-MSG-001): the click as gathered by the redirect, IP already
 * truncated, never the raw IP.
 */
final readonly class RecordShortLinkClick
{
    /**
     * Wraps the payload.
     */
    public function __construct(public ClickPayload $click)
    {
    }
}
