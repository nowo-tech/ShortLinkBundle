<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration\Kernel;

use Symfony\Component\DependencyInjection\Container;

use function str_starts_with;

/**
 * Standard Symfony host: the bundle boots with zero configuration and registers nothing in S0.
 */
final class KernelBootTest extends KernelTestCase
{
    public function testTheBundleBootsWithZeroConfigurationAndRegistersNothing(): void
    {
        $container = $this->bootKernel()->getContainer();

        self::assertSame('/s', $container->getParameter('nowo_short_link.redirect_path_prefix'));
        self::assertFalse($container->getParameter('nowo_short_link.ui_enabled'));
        self::assertSame('/short-links', $container->getParameter('nowo_short_link.ui_path_prefix'));
        self::assertSame('', $container->getParameter('nowo_short_link.table_prefix'));
        self::assertSame([], $this->bundleServiceIds($container));
    }

    public function testHostValuesReachTheParametersAndS0StillRegistersNoService(): void
    {
        $container = $this->bootKernel([
            'public_base_url' => '%env(SHORT_LINK_TEST_BASE_URL)%',
            'redirect'        => ['path_prefix' => '/go'],
            'ui'              => ['enabled' => true, 'path_prefix' => '/admin/links', 'tenant_route_parameter' => 'uuid'],
            'doctrine'        => ['table_prefix' => 'app_'],
        ])->getContainer();

        self::assertSame('/go', $container->getParameter('nowo_short_link.redirect_path_prefix'));
        self::assertTrue($container->getParameter('nowo_short_link.ui_enabled'));
        self::assertSame('/admin/links', $container->getParameter('nowo_short_link.ui_path_prefix'));
        self::assertSame('app_', $container->getParameter('nowo_short_link.table_prefix'));
        self::assertSame([], $this->bundleServiceIds($container));
    }

    /**
     * @return list<string>
     */
    private function bundleServiceIds(object $container): array
    {
        self::assertInstanceOf(Container::class, $container);
        $ids = [];
        foreach ($container->getServiceIds() as $id) {
            $bundleService = str_starts_with($id, 'nowo_short_link') || str_starts_with($id, 'Nowo\\ShortLinkBundle\\');
            if ($bundleService && !str_starts_with($id, 'Nowo\\ShortLinkBundle\\Tests\\')) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
