<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Exception;

use DomainException;

/**
 * The access port refused the action for this tenant.
 */
final class AccessDeniedException extends DomainException implements ShortLinkExceptionInterface
{
    /**
     * Builds the exception.
     */
    public function __construct()
    {
        parent::__construct('You are not allowed to do this with short links.');
    }
}
