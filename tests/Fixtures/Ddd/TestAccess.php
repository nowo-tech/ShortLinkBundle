<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Fixtures\Ddd;

use Nowo\ShortLinkBundle\Domain\Model\ActorRef;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkPermission;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkAccessInterface;

use function in_array;

/**
 * DDD host access port of the tests: permissions per tenant key, one current actor.
 */
final class TestAccess implements ShortLinkAccessInterface
{
    /** @var array<string, list<ShortLinkPermission>> */
    public array $granted = [];

    public ?ActorRef $actor = null;

    public function isGranted(ShortLinkPermission $permission, TenantKey $tenant): bool
    {
        return in_array($permission, $this->granted[$tenant->value] ?? [], true);
    }

    public function currentActor(): ?ActorRef
    {
        return $this->actor;
    }
}
