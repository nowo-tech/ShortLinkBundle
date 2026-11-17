<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Presentation\Routing;

use Nowo\ShortLinkBundle\Infrastructure\Routing\ControllerAttributeRouteLoader;
use Nowo\ShortLinkBundle\Presentation\Controller\ShortLinkUiController;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\RouteCollection;

/**
 * Route loader of the management UI (contract §3.5), type `nowo_short_link_ui`. Since 0.3 (REQ-SF-004) the routes are
 * `#[Route]` attributes on {@see ShortLinkUiController} (paths relative to `ui.path_prefix`); this loader adds the
 * prefix and the `ui.enabled` switch, and the collection is the 0.2 one (fixture test). The host imports
 * `@NowoShortLinkBundle/Resources/config/routes/ui.php` under its own prefix (Beacon:
 * `/organizations/{uuid}`); the routes sit under `ui.path_prefix` (default `/short-links`). With `ui.enabled: false`
 * (the default) the collection is empty, so nothing is reachable. Used while the router cache warms up only.
 */
final class UiRouteLoader extends Loader
{
    /** Loader type the host import names. */
    public const TYPE = 'nowo_short_link_ui';

    /** Lowercase UUID of a link (`ShortLinkId`). */
    public const ID_REQUIREMENT = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /**
     * @param bool $enabled `ui.enabled`
     * @param string $pathPrefix `ui.path_prefix`
     */
    public function __construct(private readonly bool $enabled, private readonly string $pathPrefix, ?string $env = null)
    {
        parent::__construct($env);
    }

    /**
     * The UI routes, or none while the UI is disabled: the `#[Route]` attributes of {@see ShortLinkUiController} in
     * declaration order, each path behind `ui.path_prefix` (prepended as is), no class aliases.
     */
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = new RouteCollection();
        if (!$this->enabled) {
            return $routes;
        }
        $loaded = (new ControllerAttributeRouteLoader($this->env))->load(ShortLinkUiController::class);
        foreach ($loaded->all() as $name => $route) {
            // The index attribute path `/` is the prefix itself (`/short-links`, no trailing slash).
            $path = $route->getPath() === '/' ? $this->pathPrefix : $this->pathPrefix . $route->getPath();
            $routes->add($name, $route->setPath($path));
        }
        foreach ($loaded->getResources() as $classResource) {
            $routes->addResource($classResource);
        }

        return $routes;
    }

    /**
     * Only the `nowo_short_link_ui` type.
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }
}
