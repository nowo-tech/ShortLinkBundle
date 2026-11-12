<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration\Database;

use Nowo\ShortLinkBundle\Domain\Model\ActorRef;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkPermission;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Tests\Fixtures\Ddd\TestAccess;
use Nowo\ShortLinkBundle\Tests\Fixtures\Ddd\TestQuota;
use Nowo\ShortLinkBundle\Tests\Fixtures\Ddd\TestTenantContext;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\Host;

use function assert;

/**
 * Management UI on the DDD host: XML mapping on a dedicated entity manager, two tenants, tenant context / access /
 * quota ports implemented by the host (per-tenant permissions).
 */
final class UiDddHostTest extends UiTestCase
{
    protected function setUp(): void
    {
        $this->tenant      = TenantKey::fromString('org-a');
        $this->otherTenant = TenantKey::fromString('org-b');
    }

    public function testWithoutATenantEveryPageIsNotFound(): void
    {
        $this->boot();
        $link                    = $this->create('https://example.com');
        $this->context()->tenant = null;
        foreach (['', '/new', '/' . $link->id->value . '/stats'] as $path) {
            $this->client->request('GET', self::BASE . $path);
            self::assertSame(404, $this->httpStatus(), $path);
        }
    }

    public function testPermissionsInAnotherTenantGrantNothingHere(): void
    {
        $this->boot();
        $this->access()->granted = [$this->otherTenant->value => [ShortLinkPermission::View, ShortLinkPermission::Manage]];
        $this->client->request('GET', self::BASE);
        self::assertSame(403, $this->httpStatus());
    }

    protected function host(): Host
    {
        return Host::ddd();
    }

    protected function boot(array $config = []): void
    {
        parent::boot($config);
        $this->context()->tenant = $this->tenant;
    }

    protected function grant(?TenantKey $tenant, bool $manage = true): void
    {
        $access          = $this->access();
        $access->granted = $tenant instanceof TenantKey ? [$tenant->value => $manage ? [ShortLinkPermission::View, ShortLinkPermission::Manage] : [ShortLinkPermission::View]] : [];
        $access->actor   = ActorRef::fromString('user-1');
    }

    protected function grantOutsideRequest(TenantKey $tenant): void
    {
        // The access port is the host's service: the grants of the requests apply outside them too.
    }

    protected function setLimit(int $limit): void
    {
        $quota = $this->container()->get(TestQuota::class);
        assert($quota instanceof TestQuota);
        $quota->limits[$this->tenant->value] = $limit;
    }

    private function access(): TestAccess
    {
        $access = $this->container()->get(TestAccess::class);
        assert($access instanceof TestAccess);

        return $access;
    }

    private function context(): TestTenantContext
    {
        $context = $this->container()->get(TestTenantContext::class);
        assert($context instanceof TestTenantContext);

        return $context;
    }
}
