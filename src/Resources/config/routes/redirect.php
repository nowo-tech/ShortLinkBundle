<?php

declare(strict_types=1);

/*
 * Public redirect route (contract §3.5): import it from the host, outside any locale prefix, e.g.
 *
 *     # config/routes/nowo_short_link.yaml
 *     nowo_short_link_redirect:
 *         resource: '@NowoShortLinkBundle/Resources/config/routes/redirect.php'
 *
 * The path follows `nowo_short_link.redirect.path_prefix` (default `/s`). Stateless; GET and HEAD only (other methods
 * answer 405 with `Allow: GET, HEAD`).
 */

use Nowo\ShortLinkBundle\Infrastructure\Routing\RedirectRouteLoader;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// Since 0.3 (REQ-SF-004) the route is the #[Route] attribute of Infrastructure\Http\RedirectController, read by the
// `nowo_short_link_redirect` loader; this file stays the documented import (same name, path, methods, requirements).
return static function (RoutingConfigurator $routes): void {
    $routes->import('.', RedirectRouteLoader::TYPE);
};
