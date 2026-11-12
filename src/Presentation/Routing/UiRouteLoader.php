<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Presentation\Routing;

use Nowo\ShortLinkBundle\Presentation\Controller\ShortLinkUiController;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Route loader of the management UI (contract §3.5), type `nowo_short_link_ui`. The host imports
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
     * The UI routes, or none while the UI is disabled.
     */
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = new RouteCollection();
        if (!$this->enabled) {
            return $routes;
        }
        $controller = ShortLinkUiController::class;
        $id         = ['id' => self::ID_REQUIREMENT];
        foreach ([
            'nowo_short_link_index'     => ['', 'index', ['GET'], []],
            'nowo_short_link_new'       => ['/new', 'create', ['GET', 'POST'], []],
            'nowo_short_link_edit'      => ['/{id}/edit', 'edit', ['GET', 'POST'], $id],
            'nowo_short_link_disable'   => ['/{id}/disable', 'disable', ['POST'], $id],
            'nowo_short_link_enable'    => ['/{id}/enable', 'enable', ['POST'], $id],
            'nowo_short_link_delete'    => ['/{id}/delete', 'delete', ['POST'], $id],
            'nowo_short_link_stats'     => ['/{id}/stats', 'stats', ['GET'], $id],
            'nowo_short_link_stats_csv' => ['/{id}/stats.csv', 'statsCsv', ['GET'], $id],
        ] as $name => [$path, $action, $methods, $requirements]) {
            $routes->add($name, new Route($this->pathPrefix . $path, ['_controller' => $controller . '::' . $action], $requirements, [], '', [], $methods));
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
