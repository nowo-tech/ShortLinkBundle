<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration\Database;

use Closure;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\Host;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserProviderInterface;

use function array_replace_recursive;
use function assert;

/**
 * Management UI on the standard host: single tenant `default`, roles through SecurityBundle (`ROLE_SL_VIEW` views,
 * `ROLE_ADMIN` manages), plan limit from `quota.default_limit`.
 */
final class UiStandardHostTest extends UiTestCase
{
    private const ADMIN_ROLES = ['ROLE_ADMIN', 'ROLE_SL_VIEW'];

    protected function setUp(): void
    {
        $this->tenant      = TenantKey::default();
        $this->otherTenant = TenantKey::fromString('other');
    }

    protected function host(): Host
    {
        return Host::standard();
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function boot(array $config = []): void
    {
        /** @var array<string, mixed> $merged */
        $merged = array_replace_recursive(['access' => ['view_role' => 'ROLE_SL_VIEW', 'manage_role' => 'ROLE_ADMIN'], 'quota' => ['default_limit' => 1000]], $config);
        parent::boot($merged);
    }

    protected function hostConfigure(): Closure
    {
        $configure = parent::hostConfigure();

        return static function (ContainerConfigurator $container, string $url) use ($configure): void {
            $configure($container, $url);
            $container->extension('security', [
                'providers' => ['users' => ['memory' => ['users' => [
                    'ui-viewer'  => ['roles' => ['ROLE_SL_VIEW']],
                    'ui-manager' => ['roles' => self::ADMIN_ROLES],
                ]]]],
            ]);
        };
    }

    protected function grant(?TenantKey $tenant, bool $manage = true): void
    {
        $this->client->getCookieJar()->clear();
        if (!$tenant instanceof TenantKey) {
            return;
        }
        // The user of the provider itself (its generated password), so the firewall's refresh keeps the token.
        $users = $this->client->getContainer()->get('security.user.provider.concrete.users');
        assert($users instanceof UserProviderInterface);
        $this->client->loginUser($users->loadUserByIdentifier($manage ? 'ui-manager' : 'ui-viewer'), 'main');
    }

    protected function grantOutsideRequest(TenantKey $tenant): void
    {
        $services = $this->container()->get('test.service_container');
        assert($services instanceof ContainerInterface);
        $tokens = $services->get('security.token_storage');
        assert($tokens instanceof TokenStorageInterface);
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('ui-manager', null, self::ADMIN_ROLES), 'main', self::ADMIN_ROLES));
    }

    protected function setLimit(int $limit): void
    {
        $this->boot(['quota' => ['default_limit' => $limit]]);
    }
}
