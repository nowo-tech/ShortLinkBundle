<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Exception;

use Throwable;

/**
 * Marker of every exception the bundle throws on purpose (catch it to handle any short-link refusal).
 */
interface ShortLinkExceptionInterface extends Throwable
{
}
