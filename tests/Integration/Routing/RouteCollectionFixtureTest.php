<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration\Routing;

use Nowo\ShortLinkBundle\Tests\Integration\Kernel\Host;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\KernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function str_starts_with;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * REQ-SF-004 (attribute routes): the route collection hosts get from the two documented imports
 * (`Resources/config/routes/redirect.php` and `Resources/config/routes/ui.php`, here under the host prefix
 * `/t/{workspace}`) is pinned by `tests/Fixtures/Routing/route-collections.json`, dumped from 1.0 (redirect route in a
 * PHP routing file, UI routes built in PHP by the `nowo_short_link_ui` loader) before the routes moved to `#[Route]`
 * attributes. Every route keeps its name, order, path, methods, requirements, defaults, options, host, schemes and
 * condition, on the standard and the DDD host, with the default and with custom prefixes.
 * `SHORT_LINK_ROUTE_FIXTURE_WRITE=1` rewrites the fixture (only to record an intended change).
 */
final class RouteCollectionFixtureTest extends KernelTestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixtures/Routing/route-collections.json';

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function scenarios(): iterable
    {
        foreach (['zero_config', 'standard_ui', 'ddd_ui', 'custom_prefixes', 'ui_disabled_custom_prefix'] as $scenario) {
            yield $scenario => [$scenario];
        }
    }

    #[DataProvider('scenarios')]
    public function testRouteCollectionMatchesTheFixture(string $scenario): void
    {
        $actual = $this->export($this->routes($scenario));

        if (getenv('SHORT_LINK_ROUTE_FIXTURE_WRITE') === '1') {
            $all            = $this->fixture();
            $all[$scenario] = $actual;
            file_put_contents(self::FIXTURE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        }

        $expected = $this->fixture()[$scenario] ?? null;
        self::assertIsArray($expected, 'No fixture for scenario ' . $scenario);
        self::assertSame($expected, $actual);
    }

    private function routes(string $scenario): RouteCollection
    {
        $kernel = match ($scenario) {
            'zero_config'     => $this->bootKernel(),
            'standard_ui'     => $this->bootKernel(Host::standard()->bundleConfig(['ui' => ['enabled' => true]]), null, Host::standard()->configure(), true, true),
            'ddd_ui'          => $this->bootKernel(Host::ddd()->bundleConfig(['ui' => ['enabled' => true]]), null, Host::ddd()->configure(), false, true),
            'custom_prefixes' => $this->bootKernel(Host::standard()->bundleConfig([
                'redirect' => ['path_prefix' => '/go'],
                'ui'       => ['enabled' => true, 'path_prefix' => '/manage/links', 'tenant_route_parameter' => 'workspace'],
            ]), null, Host::standard()->configure(), true, true),
            'ui_disabled_custom_prefix' => $this->bootKernel(['redirect' => ['path_prefix' => '/l/r'], 'ui' => ['path_prefix' => '/admin/l']]),
            default                     => self::fail('Unknown scenario ' . $scenario),
        };

        $router = $kernel->getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        return $router->getRouteCollection();
    }

    /**
     * The bundle routes (by `_controller`) in collection order, with every attribute of each route, and the aliases.
     *
     * @return array{routes: list<array<string, mixed>>, aliases: array<string, string>}
     */
    private function export(RouteCollection $collection): array
    {
        $routes = [];
        foreach ($collection->all() as $name => $route) {
            $controller = $route->getDefault('_controller');
            if (!is_string($controller) || !str_starts_with($controller, 'Nowo\\ShortLinkBundle\\') || str_starts_with($controller, 'Nowo\\ShortLinkBundle\\Tests\\')) {
                continue;
            }
            $routes[] = [
                'name'         => $name,
                'path'         => $route->getPath(),
                'host'         => $route->getHost(),
                'schemes'      => $route->getSchemes(),
                'methods'      => $route->getMethods(),
                'defaults'     => $route->getDefaults(),
                'requirements' => $route->getRequirements(),
                'options'      => $route->getOptions(),
                'condition'    => $route->getCondition(),
            ];
        }

        $aliases = [];
        foreach ($collection->getAliases() as $name => $alias) {
            if (str_starts_with($name, 'Nowo\\ShortLinkBundle\\') || str_starts_with($alias->getId(), 'nowo_short_link')) {
                $aliases[$name] = $alias->getId();
            }
        }

        return ['routes' => $routes, 'aliases' => $aliases];
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $json = @file_get_contents(self::FIXTURE);
        if (!is_string($json)) {
            return [];
        }
        /** @var array<string, mixed>|null $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : [];
    }
}
