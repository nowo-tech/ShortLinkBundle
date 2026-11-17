<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Routing;

use Nowo\ShortLinkBundle\Infrastructure\Http\RedirectController;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\RouteCollection;

/**
 * REQ-SF-004: route loader of the public redirect (contract §3.5), type `nowo_short_link_redirect`, imported by
 * `@NowoShortLinkBundle/Resources/config/routes/redirect.php` (hosts keep importing that file). Reads the `#[Route]`
 * attribute of {@see RedirectController}: `nowo_short_link_redirect`, `%nowo_short_link.redirect_path_prefix%/{code}`
 * (the router resolves the parameter, as before), GET and HEAD, `code: [^/]+`, stateless. Used while the router cache
 * warms up only; no state.
 */
final class RedirectRouteLoader extends Loader
{
    /** Loader type `redirect.php` imports. */
    public const TYPE = 'nowo_short_link_redirect';

    /**
     * The redirect route, rebuilt from the attribute (no class aliases: the collection is the one the PHP routing file
     * produced before 0.3).
     */
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $loaded = (new ControllerAttributeRouteLoader($this->env))->load(RedirectController::class);
        $routes = new RouteCollection();
        foreach ($loaded->all() as $name => $route) {
            $routes->add($name, $route);
        }
        foreach ($loaded->getResources() as $classResource) {
            $routes->addResource($classResource);
        }

        return $routes;
    }

    /**
     * Only the `nowo_short_link_redirect` type.
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }
}
