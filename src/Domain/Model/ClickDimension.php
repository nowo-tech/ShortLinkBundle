<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

/**
 * Breakdown dimensions of the statistics (stored in the daily rollup as `referrer`, `country`, `ua`).
 */
enum ClickDimension: string
{
    case Referrer = 'referrer';
    case Country  = 'country';
    case UaFamily = 'ua';
}
