<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Exception;

use DomainException;

/**
 * No live link with this id in this tenant (another tenant's id answers the same way).
 */
final class ShortLinkNotFoundException extends DomainException implements ShortLinkExceptionInterface
{
    /**
     * Builds the exception.
     */
    public function __construct()
    {
        parent::__construct('Short link not found.');
    }
}
