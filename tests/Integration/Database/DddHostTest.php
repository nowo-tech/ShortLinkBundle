<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration\Database;

use Nowo\ShortLinkBundle\Domain\Model\ActorRef;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkPermission;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkTenantContextInterface;
use Nowo\ShortLinkBundle\Domain\Port\TenantLockInterface;
use Nowo\ShortLinkBundle\Infrastructure\Lock\NoTenantLock;
use Nowo\ShortLinkBundle\Tests\Fixtures\Ddd\TestAccess;
use Nowo\ShortLinkBundle\Tests\Fixtures\Ddd\TestQuota;
use Nowo\ShortLinkBundle\Tests\Fixtures\Ddd\TestTenantContext;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\Host;

/**
 * DDD host: XML mapping on a dedicated entity manager and connection, table prefix `ddd_`, two tenants (organization
 * UUIDs), host tenant context / access / quota ports, no lock factory.
 */
final class DddHostTest extends ShortLinkFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->tenant      = TenantKey::fromString('0190a6d0-aaaa-7000-8000-00000000000a');
        $this->otherTenant = TenantKey::fromString('0190a6d0-bbbb-7000-8000-00000000000b');
        parent::setUp();
    }

    public function testTheHostPortsAreUsed(): void
    {
        $context = $this->container()->get(ShortLinkTenantContextInterface::class);
        self::assertInstanceOf(TestTenantContext::class, $context);
        self::assertInstanceOf(NoTenantLock::class, $this->container()->get(TenantLockInterface::class));
        self::assertTrue($this->host()->connection($this->kernel)->createSchemaManager()->tablesExist(['ddd_nowo_short_link']));
    }

    protected function host(): Host
    {
        return Host::ddd();
    }

    protected function grant(array $tenants, ?string $actor = 'actor-1'): void
    {
        $access          = $this->access();
        $access->granted = [];
        foreach ($tenants as $tenant) {
            $access->granted[$tenant->value] = [ShortLinkPermission::View, ShortLinkPermission::Manage];
        }
        $access->actor = $actor === null ? null : ActorRef::fromString($actor);
    }

    protected function revoke(): void
    {
        $this->access()->granted = [];
        $this->access()->actor   = null;
    }

    protected function setLimit(int $limit): void
    {
        $quota = $this->container()->get(TestQuota::class);
        self::assertInstanceOf(TestQuota::class, $quota);
        $quota->limits[$this->tenant->value] = $limit;
    }

    private function access(): TestAccess
    {
        $access = $this->container()->get(TestAccess::class);
        self::assertInstanceOf(TestAccess::class, $access);

        return $access;
    }
}
