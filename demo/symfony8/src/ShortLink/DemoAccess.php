<?php

declare(strict_types=1);

namespace App\ShortLink;

use Nowo\ShortLinkBundle\Domain\Model\ActorRef;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkPermission;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkAccessInterface;

/**
 * Demo access port: the demo has no login, so every visitor may view and manage the links of the single tenant
 * `default` and is recorded as the actor `demo`. A real host checks its own permissions here (Beacon:
 * `organization.short_links.view` / `.manage` per organization).
 */
final readonly class DemoAccess implements ShortLinkAccessInterface
{
    public function isGranted(ShortLinkPermission $permission, TenantKey $tenant): bool
    {
        return $tenant->value === TenantKey::default()->value;
    }

    public function currentActor(): ActorRef
    {
        return ActorRef::fromString('demo');
    }
}
