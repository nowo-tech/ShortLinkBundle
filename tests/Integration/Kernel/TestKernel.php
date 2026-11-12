<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration\Kernel;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\ShortLinkBundle\NowoShortLinkBundle;
use Nowo\ShortLinkBundle\Tests\Fixtures\EchoController;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function dirname;
use function sys_get_temp_dir;

/**
 * Standard-host kernel of the integration tests: FrameworkBundle, the bundle and, on demand, DoctrineBundle (DBAL
 * only). One route, `/echo`, answers the request's `X-Test-Tenant` header and the bundle's redirect prefix parameter.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $bundleConfig `nowo_short_link` configuration
     * @param string|null $databaseUrl DBAL URL; null = no DoctrineBundle
     */
    public function __construct(
        private readonly string $id,
        private readonly array $bundleConfig = [],
        private readonly ?string $databaseUrl = null,
    ) {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        if ($this->databaseUrl !== null) {
            yield new DoctrineBundle();
        }
        yield new NowoShortLinkBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return self::baseDir() . '/' . $this->id . '/cache';
    }

    public function getLogDir(): string
    {
        return self::baseDir() . '/' . $this->id . '/log';
    }

    public static function baseDir(): string
    {
        return sys_get_temp_dir() . '/nowo-short-link-tests';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test'                  => true,
            'secret'                => 'S0-' . $this->id,
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'php_errors'            => ['log' => true],
            'router'                => ['utf8' => true],
        ]);
        if ($this->databaseUrl !== null) {
            $container->extension('doctrine', ['dbal' => ['url' => $this->databaseUrl]]);
        }
        $container->extension('nowo_short_link', $this->bundleConfig);
        $container->services()->set('logger', NullLogger::class);
        $container->services()
            ->set(EchoController::class)
            ->arg('$redirectPrefix', '%nowo_short_link.redirect_path_prefix%')
            ->public()
            ->tag('controller.service_arguments');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('echo', '/echo')->controller(EchoController::class)->methods(['GET']);
    }
}
