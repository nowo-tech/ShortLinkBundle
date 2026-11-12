<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Exception;

/**
 * A custom code is reserved (built-in list, `code.reserved`, the host provider) or blocklisted. Same neutral message
 * as {@see CodeTakenException}, which it extends.
 */
final class CodeReservedException extends CodeTakenException
{
}
