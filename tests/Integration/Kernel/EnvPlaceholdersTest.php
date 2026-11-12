<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration\Kernel;

use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkQuotaInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Audit NY-S4 N1: every configuration key with a custom validator, given as an env placeholder, in a real compiled
 * container (MergeExtensionConfigurationPass loads the extension with the placeholder, ValidateEnvPlaceholdersPass
 * re-processes the tree with the placeholder's type default). Keys read at runtime accept it; keys needed while
 * compiling refuse it with a clear message; a literal empty value is still refused.
 */
final class EnvPlaceholdersTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, array<string, string>}>
     */
    public static function acceptedKeys(): iterable
    {
        yield 'public_base_url' => [['public_base_url' => '%env(SL_BASE)%'], ['SL_BASE' => 'https://links.example']];
        yield 'quota.default_limit' => [['quota' => ['default_limit' => '%env(int:SL_QUOTA)%']], ['SL_QUOTA' => '7']];
        yield 'code.quarantine' => [['code' => ['quarantine' => '%env(SL_QUARANTINE)%']], ['SL_QUARANTINE' => 'P30D']];
        yield 'click_log.retention' => [['click_log' => ['retention' => '%env(SL_RETENTION)%']], ['SL_RETENTION' => 'P90D']];
        yield 'click_log.rollup.retention' => [['click_log' => ['rollup' => ['retention' => '%env(SL_ROLLUP)%']]], ['SL_ROLLUP' => 'P5Y']];
        yield 'redirect.rate_limit.misses.interval' => [['redirect' => ['rate_limit' => ['misses' => ['interval' => '%env(SL_MISS)%']]]], ['SL_MISS' => '5 minutes']];
        yield 'redirect.rate_limit.hits.interval' => [['redirect' => ['rate_limit' => ['hits' => ['interval' => '%env(SL_HIT)%']]]], ['SL_HIT' => '1 hour']];
        yield 'code.custom_code_rate_limit.interval' => [['code' => ['custom_code_rate_limit' => ['interval' => '%env(SL_CODES)%']]], ['SL_CODES' => '2 minutes']];
        yield 'code.custom_code_rate_limit.limit' => [['code' => ['custom_code_rate_limit' => ['limit' => '%env(int:SL_CODES_LIMIT)%']]], ['SL_CODES_LIMIT' => '5']];
        yield 'code.reserved' => [['code' => ['reserved' => ['%env(SL_RESERVED)%']]], ['SL_RESERVED' => 'brand']];
        yield 'target.own_hosts' => [['target' => ['own_hosts' => ['%env(SL_HOST)%']]], ['SL_HOST' => 'links.example']];
        yield 'target.denylist.hosts' => [['target' => ['denylist' => ['hosts' => ['%env(SL_DENY)%']]]], ['SL_DENY' => '.bad.example']];
        yield 'target.self_paths' => [['target' => ['self_paths' => ['%env(SL_SELF)%']]], ['SL_SELF' => '/go/']];
        yield 'ui.tenant_route_parameter' => [['ui' => ['tenant_route_parameter' => '%env(SL_PARAM)%']], ['SL_PARAM' => 'uuid']];
        yield 'ui.page_size' => [['ui' => ['page_size' => '%env(int:SL_PAGE)%']], ['SL_PAGE' => '50']];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $env
     */
    #[DataProvider('acceptedKeys')]
    public function testARuntimeKeyAcceptsAnEnvPlaceholder(array $config, array $env): void
    {
        self::assertTrue($this->boot($config, $env)->has('test.service_container'));
    }

    public function testTheQuotaPlaceholderIsResolvedAtRuntime(): void
    {
        $services = $this->boot(['quota' => ['default_limit' => '%env(int:SL_QUOTA)%']], ['SL_QUOTA' => '7'])->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $services);
        $quota = $services->get(ShortLinkQuotaInterface::class);
        self::assertInstanceOf(ShortLinkQuotaInterface::class, $quota);
        self::assertSame(7, $quota->limitFor(TenantKey::default(), LinkKind::manual()));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function compileTimeKeys(): iterable
    {
        yield 'redirect.path_prefix' => [['redirect' => ['path_prefix' => '%env(SL_PREFIX)%']], 'nowo_short_link.redirect.path_prefix cannot be an environment variable'];
        yield 'ui.path_prefix' => [['ui' => ['path_prefix' => '%env(SL_UI)%']], 'nowo_short_link.ui.path_prefix cannot be an environment variable'];
        yield 'doctrine.table_prefix' => [['doctrine' => ['table_prefix' => '%env(SL_TABLES)%']], 'nowo_short_link.doctrine.table_prefix cannot be an environment variable'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('compileTimeKeys')]
    public function testACompileTimeKeyRefusesAnEnvPlaceholderClearly(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);
        $this->boot($config, []);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function emptyValues(): iterable
    {
        yield 'quarantine' => [['code' => ['quarantine' => '']], 'nowo_short_link.code.quarantine'];
        yield 'retention' => [['click_log' => ['retention' => '']], 'nowo_short_link.click_log.retention'];
        yield 'interval' => [['redirect' => ['rate_limit' => ['hits' => ['interval' => '']]]], 'nowo_short_link.redirect.rate_limit.hits.interval'];
        yield 'custom interval' => [['code' => ['custom_code_rate_limit' => ['interval' => '']]], 'nowo_short_link.code.custom_code_rate_limit.interval'];
        yield 'tenant parameter' => [['ui' => ['tenant_route_parameter' => '']], 'nowo_short_link.ui.tenant_route_parameter'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('emptyValues')]
    public function testALiteralEmptyValueIsStillRefused(array $config, string $path): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($path);
        $this->boot($config, []);
    }

    /**
     * A compiled kernel whose env values come from `env(NAME)` default parameters (no process environment change).
     *
     * @param array<string, mixed> $config
     * @param array<string, string> $env
     */
    private function boot(array $config, array $env): ContainerInterface
    {
        return $this->bootKernel($config, null, static function (ContainerConfigurator $container, string $url) use ($env): void {
            $container->extension('doctrine', ['dbal' => ['url' => $url], 'orm' => []]);
            foreach ($env as $name => $value) {
                $container->parameters()->set('env(' . $name . ')', $value);
            }
        })->getContainer();
    }
}
