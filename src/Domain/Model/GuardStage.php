<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

/**
 * When a target is checked: on save (create, update, enable; DNS allowed), on every redirect (no DNS, cheap denylist
 * path) or by the recheck command (DNS allowed).
 */
enum GuardStage: string
{
    case Save     = 'save';
    case Redirect = 'redirect';
    case Recheck  = 'recheck';
}
