<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Exception;

use DomainException;

/**
 * The person tried too many custom codes in a short time (contract §6.2, audits L7 / NY-S4): custom codes are not
 * looked up again until the limit window ends. The message names no code.
 */
final class CodeAttemptsExceededException extends DomainException implements ShortLinkExceptionInterface
{
    /** Message shown to people. */
    public const MESSAGE = 'Too many custom codes were tried. Try again later.';

    /**
     * Builds the exception.
     */
    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
