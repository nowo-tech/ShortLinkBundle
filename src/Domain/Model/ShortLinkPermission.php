<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

/**
 * What the access port is asked: view links and stats, or manage them (create, edit, disable, enable, delete).
 */
enum ShortLinkPermission: string
{
    case View   = 'view';
    case Manage = 'manage';
}
