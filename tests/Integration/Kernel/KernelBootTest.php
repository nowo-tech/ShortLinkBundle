<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration\Kernel;

use LogicException;
use Nowo\ShortLinkBundle\Application\Default\ConfigReservedCodes;
use Nowo\ShortLinkBundle\Application\Default\SingleTenantContext;
use Nowo\ShortLinkBundle\Application\Guard\ChainTargetGuard;
use Nowo\ShortLinkBundle\Application\ShortUrlBuilderInterface;
use Nowo\ShortLinkBundle\DependencyInjection\Compiler\ShortLinkCompilerPass;
use Nowo\ShortLinkBundle\Domain\Model\ShortCode;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkPermission;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Port\ReservedCodeProviderInterface;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkAccessInterface;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkTenantContextInterface;
use Nowo\ShortLinkBundle\Domain\Port\TargetGuardInterface;
use Nowo\ShortLinkBundle\Domain\Port\TenantLockInterface;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkManagerInterface;
use Nowo\ShortLinkBundle\Infrastructure\Lock\NoTenantLock;
use Nowo\ShortLinkBundle\Infrastructure\Security\RoleBasedAccess;
use Nowo\ShortLinkBundle\NowoShortLinkBundle;
use Nowo\ShortLinkBundle\Tests\Fixtures\Ddd\TestTenantContext;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Standard Symfony host: the bundle boots with zero configuration and wires its defaults; host values reach the
 * parameters and host services replace the ports.
 */
final class KernelBootTest extends KernelTestCase
{
    public function testTheBundleBootsWithZeroConfigurationAndWiresTheDefaults(): void
    {
        $container = $this->bootKernel()->getContainer();

        self::assertSame('/s', $container->getParameter('nowo_short_link.redirect_path_prefix'));
        self::assertFalse($container->getParameter('nowo_short_link.ui_enabled'));
        self::assertSame('/short-links', $container->getParameter('nowo_short_link.ui_path_prefix'));
        self::assertSame('', $container->getParameter('nowo_short_link.table_prefix'));
        self::assertInstanceOf(ShortLinkManagerInterface::class, $container->get(ShortLinkManagerInterface::class));
        self::assertInstanceOf(SingleTenantContext::class, $container->get(ShortLinkTenantContextInterface::class));
        self::assertInstanceOf(RoleBasedAccess::class, $container->get(ShortLinkAccessInterface::class));
        self::assertInstanceOf(ConfigReservedCodes::class, $container->get(ReservedCodeProviderInterface::class));
        self::assertInstanceOf(ChainTargetGuard::class, $container->get(TargetGuardInterface::class));
        self::assertInstanceOf(NoTenantLock::class, $container->get(TenantLockInterface::class));
        self::assertFalse($container->get(ShortLinkAccessInterface::class)->isGranted(ShortLinkPermission::View, TenantKey::default()), 'no SecurityBundle: denied');
    }

    public function testHostValuesReachTheParametersAndHostServicesReplaceThePorts(): void
    {
        $kernel = $this->bootKernel([
            'public_base_url' => '%env(SHORT_LINK_TEST_BASE_URL)%',
            'redirect'        => ['path_prefix' => '/go'],
            'ui'              => ['enabled' => true, 'path_prefix' => '/admin/links', 'tenant_route_parameter' => 'uuid'],
            'doctrine'        => ['table_prefix' => 'app_', 'mapping' => 'attribute'],
            'tenancy'         => ['context' => TestTenantContext::class],
            'target'          => ['guard' => 'app.guard'],
        ], null, static function ($container, string $url): void {
            $container->parameters()->set('env(SHORT_LINK_TEST_BASE_URL)', 'https://beacon.example');
            $container->extension('doctrine', ['dbal' => ['url' => $url], 'orm' => []]);
            $container->services()->set(TestTenantContext::class);
            $container->services()->set('app.guard', ChainTargetGuard::class)->args([[]]);
        });
        $container = $kernel->getContainer();

        self::assertSame('/go', $container->getParameter('nowo_short_link.redirect_path_prefix'));
        self::assertTrue($container->getParameter('nowo_short_link.ui_enabled'));
        self::assertSame('/admin/links', $container->getParameter('nowo_short_link.ui_path_prefix'));
        self::assertSame('app_', $container->getParameter('nowo_short_link.table_prefix'));
        self::assertInstanceOf(TestTenantContext::class, $container->get(ShortLinkTenantContextInterface::class));
        self::assertInstanceOf(ChainTargetGuard::class, $container->get(TargetGuardInterface::class));
        $test = $container->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $test);
        $urls = $test->get(ShortUrlBuilderInterface::class);
        self::assertInstanceOf(ShortUrlBuilderInterface::class, $urls);
        self::assertSame('https://beacon.example/go/abc123x', $urls->build(ShortCode::random('abc123x')));
    }

    public function testTheBundleNeedsDoctrine(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        (new NowoShortLinkBundle())->build($container);
        foreach ($container->getCompilerPassConfig()->getBeforeOptimizationPasses() as $pass) {
            if ($pass instanceof ShortLinkCompilerPass) {
                $this->expectException(LogicException::class);
                $this->expectExceptionMessage('DoctrineBundle');
                $pass->process($container);
            }
        }
        self::fail('The compiler pass is registered.');
    }
}
