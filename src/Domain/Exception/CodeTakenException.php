<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Exception;

use DomainException;
use Throwable;

/**
 * A custom code is not available. The message is the same for a taken, reserved, blocked or quarantined code, so a
 * form never reveals which other tenant owns it (contract §6.2).
 */
class CodeTakenException extends DomainException implements ShortLinkExceptionInterface
{
    /** Neutral message shown to people. */
    public const MESSAGE = 'This code is not available.';

    /**
     * Builds the neutral exception.
     */
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(self::MESSAGE, 0, $previous);
    }
}
