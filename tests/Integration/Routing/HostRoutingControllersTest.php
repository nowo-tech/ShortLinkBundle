<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration\Routing;

use Nowo\ShortLinkBundle\Tests\Integration\Kernel\Host;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\RouterInterface;

use function class_exists;
use function is_string;
use function str_starts_with;

/**
 * REQ-SF-004 rule 7: the controllers carry `#[Route]` attributes, but the routes stay opt-in. A host whose routing is
 * only Symfony's default `routing.controllers` import (Symfony >= 7.3) gets no bundle route, even with the UI enabled;
 * they exist only where the host imports `routes/redirect.php` / `routes/ui.php`. Fails if a bundle controller
 * definition is autoconfigured (tag `routing.controller`).
 */
final class HostRoutingControllersTest extends TestCase
{
    protected function tearDown(): void
    {
        (new Filesystem())->remove(TestKernel::baseDir());
    }

    public function testRoutingControllersResourceDoesNotLoadTheBundleRoutes(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('The routing.controllers resource needs symfony/routing >= 7.3.');
        }

        $host   = Host::standard();
        $kernel = new TestKernel('routing_controllers_only', $host->bundleConfig(['ui' => ['enabled' => true]]), null, $host->configure(), true, true, true);
        $kernel->boot();

        try {
            $router = $kernel->getContainer()->get('router');
            self::assertInstanceOf(RouterInterface::class, $router);
            $bundleRoutes = [];
            foreach ($router->getRouteCollection()->all() as $name => $route) {
                $controller = $route->getDefault('_controller');
                if (str_starts_with($name, 'nowo_short_link') || (is_string($controller) && str_starts_with($controller, 'Nowo\\ShortLinkBundle\\'))) {
                    $bundleRoutes[] = $name;
                }
            }
            self::assertSame([], $bundleRoutes, 'The bundle routes must only exist when the host imports them.');
            self::assertSame([], $router->getRouteCollection()->getAliases());
        } finally {
            $kernel->shutdown();
        }
    }
}
