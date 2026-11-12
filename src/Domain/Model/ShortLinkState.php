<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

/**
 * State of a link as the redirect sees it. `Expired` is computed from `expires_at`, `Deleted` is a tombstone.
 */
enum ShortLinkState: string
{
    case Active   = 'active';
    case Disabled = 'disabled';
    case Expired  = 'expired';
    case Deleted  = 'deleted';
}
