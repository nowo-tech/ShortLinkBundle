<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\ShortLinkBundle\NowoShortLinkBundle;
use Nowo\ShortLinkBundle\Tests\Fixtures\EchoController;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function dirname;
use function getenv;
use function is_string;
use function sys_get_temp_dir;

/**
 * Kernel of the integration tests: FrameworkBundle, DoctrineBundle (ORM), optionally SecurityBundle, and the bundle.
 * One host route, `/echo`, answers the request's `X-Test-Tenant` header and the redirect prefix parameter.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $bundleConfig `nowo_short_link` configuration
     * @param string|null $databaseUrl DBAL URL; null = a SQLite file
     * @param (Closure(ContainerConfigurator, string): void)|null $configure host configuration (Doctrine, security, services)
     */
    public function __construct(
        private readonly string $id,
        private readonly array $bundleConfig = [],
        private readonly ?string $databaseUrl = null,
        private readonly ?Closure $configure = null,
        private readonly bool $security = false,
    ) {
        parent::__construct('test', false);
    }

    /**
     * `NOWO_TEST_DATABASE_URL` (CI matrix: MySQL, MariaDB, PostgreSQL) or a SQLite file.
     */
    public static function defaultDatabaseUrl(): string
    {
        $url = getenv('NOWO_TEST_DATABASE_URL');
        if (is_string($url) && $url !== '') {
            return $url;
        }
        (new Filesystem())->mkdir(self::baseDir());

        return 'sqlite:///' . self::baseDir() . '/functional.sqlite';
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        if ($this->security) {
            yield new SecurityBundle();
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
            'secret'                => 'S1-' . $this->id,
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'php_errors'            => ['log' => true],
            'router'                => ['utf8' => true, 'default_uri' => 'https://links.example'],
        ]);
        $url = $this->databaseUrl ?? self::defaultDatabaseUrl();
        if ($this->configure instanceof Closure) {
            ($this->configure)($container, $url);
        } else {
            $container->extension('doctrine', ['dbal' => ['url' => $url], 'orm' => []]);
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
