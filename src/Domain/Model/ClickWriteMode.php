<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

/**
 * When a click row is written (`click_log.write`, Q-O11): after the response (`kernel.terminate`, default), through a
 * Messenger message, or before answering (`sync`, tests and tiny hosts).
 */
enum ClickWriteMode: string
{
    case Terminate = 'terminate';
    case Messenger = 'messenger';
    case Sync      = 'sync';
}
