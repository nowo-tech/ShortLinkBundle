<?php

declare(strict_types=1);

/*
 * Management UI routes (contract §3.5, §11): import them from the host under its own prefix, e.g. Beacon
 *
 *     # config/routes/nowo_short_link.yaml
 *     nowo_short_link_ui:
 *         resource: '@NowoShortLinkBundle/Resources/config/routes/ui.php'
 *         prefix: /organizations/{uuid}
 *         requirements: { uuid: '[0-9a-f-]{36}' }
 *
 * The pages sit under `nowo_short_link.ui.path_prefix` (default `/short-links`); set `ui.tenant_route_parameter` to the
 * name of a prefix parameter (`uuid`) so the UI keeps it in its links. With `ui.enabled: false` (the default) the
 * import adds no route.
 */

use Nowo\ShortLinkBundle\Presentation\Routing\UiRouteLoader;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import('.', UiRouteLoader::TYPE);
};
