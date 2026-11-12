<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Exception;

use DomainException;

/**
 * The tenant reached its plan limit for a counted kind.
 */
final class QuotaExceededException extends DomainException implements ShortLinkExceptionInterface
{
    /**
     * @param int $limit the limit that was reached
     */
    public function __construct(public readonly int $limit)
    {
        parent::__construct('The short-link limit of this plan is reached.');
    }
}
