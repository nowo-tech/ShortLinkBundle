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

use Nowo\ShortLinkBundle\Infrastructure\Http\RedirectController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('nowo_short_link_redirect', '%nowo_short_link.redirect_path_prefix%/{code}')
        ->controller(RedirectController::class)
        ->methods(['GET', 'HEAD'])
        ->requirements(['code' => '[^/]+'])
        ->stateless(true);
};
