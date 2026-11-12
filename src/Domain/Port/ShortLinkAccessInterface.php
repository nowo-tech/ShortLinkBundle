<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Port;

use Nowo\ShortLinkBundle\Domain\Model\ActorRef;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkPermission;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;

/**
 * Host port: who may view or manage the links of a tenant. Default: `access.view_role` / `access.manage_role`
 * through Symfony's authorization checker (anonymous is denied).
 */
interface ShortLinkAccessInterface
{
    /**
     * Whether the current person may do this in this tenant.
     */
    public function isGranted(ShortLinkPermission $permission, TenantKey $tenant): bool;

    /**
     * The current person, recorded as created_by / updated_by; null = nobody (CLI, system).
     */
    public function currentActor(): ?ActorRef;
}
