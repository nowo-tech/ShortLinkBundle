<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

use function in_array;

/**
 * Coarse browser family of a click (never the full User-Agent). `bot`, `preview` (chat / social link previews) and
 * `scanner` (e-mail security scanners) are machine traffic, shown apart (F7).
 */
enum UserAgentFamily: string
{
    case Chrome       = 'chrome';
    case Firefox      = 'firefox';
    case Safari       = 'safari';
    case Edge         = 'edge';
    case Opera        = 'opera';
    case Samsung      = 'samsung';
    case OtherBrowser = 'other_browser';
    case Bot          = 'bot';
    case Preview      = 'preview';
    case Scanner      = 'scanner';
    case Unknown      = 'unknown';

    /**
     * Whether the family is machine traffic (bot, preview, scanner).
     */
    public function isMachine(): bool
    {
        return in_array($this, [self::Bot, self::Preview, self::Scanner], true);
    }
}
