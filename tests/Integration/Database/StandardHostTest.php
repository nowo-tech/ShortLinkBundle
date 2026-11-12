<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration\Database;

use Nowo\ShortLinkBundle\Domain\Exception\AccessDeniedException;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkListQuery;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Port\TenantLockInterface;
use Nowo\ShortLinkBundle\Infrastructure\Lock\SymfonyTenantLock;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\Host;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Standard Symfony host: attribute mapping, default entity manager, single tenant `default`, ROLE_ADMIN through
 * SecurityBundle, symfony/lock for exact quotas.
 */
final class StandardHostTest extends ShortLinkFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->tenant      = TenantKey::default();
        $this->otherTenant = TenantKey::fromString('other');
        parent::setUp();
    }

    public function testTheQuotaLockUsesSymfonyLock(): void
    {
        self::assertInstanceOf(SymfonyTenantLock::class, $this->container()->get(TenantLockInterface::class));
    }

    public function testAUserWithoutTheRoleIsDenied(): void
    {
        $this->tokens()->setToken(new UsernamePasswordToken(new InMemoryUser('viewer', null, ['ROLE_USER']), 'main', ['ROLE_USER']));

        $this->expectException(AccessDeniedException::class);
        $this->manager()->list($this->tenant, new ShortLinkListQuery());
    }

    protected function host(): Host
    {
        return Host::standard();
    }

    protected function grant(array $tenants, ?string $actor = 'actor-1'): void
    {
        $this->tokens()->setToken(new UsernamePasswordToken(new InMemoryUser((string) $actor, null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']));
    }

    protected function revoke(): void
    {
        $this->tokens()->setToken(null);
    }

    protected function setLimit(int $limit): void
    {
        $this->boot(['quota' => ['default_limit' => $limit]]);
    }

    private function tokens(): TokenStorageInterface
    {
        $tokens = $this->container()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);

        return $tokens;
    }
}
