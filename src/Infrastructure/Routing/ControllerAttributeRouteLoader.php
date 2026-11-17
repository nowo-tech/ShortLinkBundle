<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Routing;

use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Routing\Loader\AttributeClassLoader;
use Symfony\Component\Routing\Route;

/**
 * REQ-SF-004: reads the `#[Route]` attributes of one bundle controller class for the bundle route loaders
 * ({@see RedirectRouteLoader} and the `nowo_short_link_ui` loader). `_controller` is the
 * class for an invokable controller and `Class::method` otherwise, first among the defaults, as the routes had before
 * the attributes. Owned by those loaders (no service, no `routing.loader` tag), so a host that decorates
 * `routing.loader.attribute` cannot change the bundle routes.
 *
 * @internal
 */
final class ControllerAttributeRouteLoader extends AttributeClassLoader
{
    /**
     * Sets `_controller` (first default) of a route read from a controller method.
     *
     * @param ReflectionClass<object> $class
     */
    protected function configureRoute(Route $route, ReflectionClass $class, ReflectionMethod $method, object $attr): void
    {
        $controller = $method->getName() === '__invoke' ? $class->getName() : $class->getName() . '::' . $method->getName();
        $route->setDefaults(['_controller' => $controller] + $route->getDefaults());
    }
}
