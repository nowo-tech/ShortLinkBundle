<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Exception;

use DomainException;

/**
 * The person got too many "this code is not available" answers in a short time (contract §6.2, audit L7): custom codes
 * are not looked up again until the limit window ends. The message names no code.
 */
final class CodeAttemptsExceededException extends DomainException implements ShortLinkExceptionInterface
{
    /** Message shown to people. */
    public const MESSAGE = 'Too many unavailable codes were tried. Try again later.';

    /**
     * Builds the exception.
     */
    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
