<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit\DependencyInjection;

use Nowo\ShortLinkBundle\DependencyInjection\ConfigNodes;
use Nowo\ShortLinkBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaultsFollowTheContract(): void
    {
        $config = $this->process([]);

        self::assertNull($config['public_base_url']);
        self::assertSame([
            'path_prefix'        => '/s',
            'status'             => 302,
            'not_found_template' => '@NowoShortLink/redirect/not_found.html.twig',
            'rate_limit'         => [
                'enabled'         => true,
                'cache_pool'      => 'cache.app',
                'misses'          => ['limit' => 20, 'interval' => '1 minute'],
                'hits'            => ['limit' => 120, 'interval' => '1 minute'],
                'over_limit_hits' => 'redirect_unlogged',
            ],
        ], $config['redirect']);
        self::assertSame(['random_length' => 7, 'allow_custom' => true, 'reserved' => [], 'blocklist' => true, 'max_attempts' => 5, 'quarantine' => 'P365D'], $config['code']);
        self::assertSame([
            'max_length'       => 2048,
            'guard'            => 'auto',
            'save_resolve_dns' => true,
            'self_paths'       => ['/s/'],
            'own_hosts'        => [],
            'denylist'         => ['hosts' => [], 'provider' => null],
        ], $config['target']);
        self::assertSame([
            'enabled'     => true,
            'write'       => 'terminate',
            'store'       => ['ip_prefix' => true, 'user_agent_family' => true, 'referrer_host' => true, 'country' => true],
            'ipv4_prefix' => 24,
            'ipv6_prefix' => 48,
            'honour_gpc'  => true,
            'retention'   => 'P90D',
            'rollup'      => ['lag_days' => 2, 'referrer_top_n' => 50, 'retention' => null],
        ], $config['click_log']);
        self::assertSame(['context' => null], $config['tenancy']);
        self::assertSame(['provider' => null, 'view_role' => 'ROLE_ADMIN', 'manage_role' => 'ROLE_ADMIN', 'enforce_in_manager' => true], $config['access']);
        self::assertSame(['provider' => null, 'default_limit' => null, 'counted_kinds' => ['manual']], $config['quota']);
        self::assertSame([
            'manual' => ['allow_custom_code' => true, 'show_in_ui' => true, 'counted' => true],
            'email'  => ['allow_custom_code' => false, 'show_in_ui' => false, 'counted' => false],
        ], $config['kinds']);
        self::assertSame(['enabled' => false, 'path_prefix' => '/short-links', 'tenant_route_parameter' => null, 'page_size' => 25, 'stats_default_days' => 30, 'csv_max_days' => 366], $config['ui']);
        self::assertSame(['entity_manager' => null, 'connection' => null, 'table_prefix' => '', 'mapping' => 'xml'], $config['doctrine']);
    }

    public function testBeaconConfigurationIsAccepted(): void
    {
        $config = $this->process([
            'public_base_url' => 'https://beacon.example/',
            'redirect'        => ['rate_limit' => ['cache_pool' => 'cache.rate_limiter', 'misses' => ['limit' => 20, 'interval' => '15 minutes']]],
            'code'            => ['reserved' => ['Billing', 'time-track', 'nowo'], 'quarantine' => null],
            'target'          => ['own_hosts' => ['Beacon.Example', '.internal.example'], 'denylist' => ['hosts' => ['.Bad.Example'], 'provider' => 'app.denylist']],
            'click_log'       => ['retention' => 'P1Y2M3W4DT5H6M7S', 'rollup' => ['retention' => 'P5Y']],
            'tenancy'         => ['context' => 'app.short_link_tenant'],
            'quota'           => ['provider' => 'app.quota', 'default_limit' => 0, 'counted_kinds' => ['manual', 'qr']],
            'kinds'           => ['qr' => ['allow_custom_code' => false], 'email' => ['show_in_ui' => true]],
            'ui'              => ['enabled' => true, 'path_prefix' => '/organizations/x/short-links', 'tenant_route_parameter' => 'uuid'],
            'doctrine'        => ['table_prefix' => 'app_', 'mapping' => 'attribute', 'entity_manager' => 'links'],
        ]);

        self::assertSame('https://beacon.example/', $config['public_base_url']);
        self::assertSame('cache.rate_limiter', $this->at($config, 'redirect', 'rate_limit', 'cache_pool'));
        self::assertSame(['billing', 'time-track', 'nowo'], $this->at($config, 'code', 'reserved'));
        self::assertNull($this->at($config, 'code', 'quarantine'));
        self::assertSame(['beacon.example', '.internal.example'], $this->at($config, 'target', 'own_hosts'));
        self::assertSame(['.bad.example'], $this->at($config, 'target', 'denylist', 'hosts'));
        self::assertSame('P5Y', $this->at($config, 'click_log', 'rollup', 'retention'));
        self::assertSame(['allow_custom_code' => false, 'show_in_ui' => true, 'counted' => true], $this->at($config, 'kinds', 'qr'));
        self::assertEquals(['allow_custom_code' => false, 'show_in_ui' => true, 'counted' => false], $this->at($config, 'kinds', 'email'));
        self::assertArrayHasKey('manual', (array) $config['kinds']);
        self::assertSame('uuid', $this->at($config, 'ui', 'tenant_route_parameter'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'base url without scheme' => [['public_base_url' => 'beacon.example'], 'public_base_url'];
        yield 'base url ftp' => [['public_base_url' => 'ftp://beacon.example'], 'public_base_url'];
        yield 'base url with query' => [['public_base_url' => 'https://beacon.example/?a=1'], 'public_base_url'];
        yield 'base url with userinfo' => [['public_base_url' => 'https://u@beacon.example'], 'public_base_url'];
        yield 'base url without host' => [['public_base_url' => 'http://:80'], 'public_base_url'];
        yield 'base url not a string' => [['public_base_url' => 5], 'public_base_url'];
        yield 'redirect prefix root' => [['redirect' => ['path_prefix' => '/']], 'path_prefix'];
        yield 'redirect prefix relative' => [['redirect' => ['path_prefix' => 's']], 'path_prefix'];
        yield 'redirect prefix trailing slash' => [['redirect' => ['path_prefix' => '/s/']], 'path_prefix'];
        yield 'redirect prefix not a string' => [['redirect' => ['path_prefix' => 1]], 'path_prefix'];
        yield 'permanent redirect' => [['redirect' => ['status' => 301]], 'status'];
        yield 'rate interval' => [['redirect' => ['rate_limit' => ['hits' => ['interval' => 'soon']]]], 'interval'];
        yield 'rate interval not a string' => [['redirect' => ['rate_limit' => ['hits' => ['interval' => 60]]]], 'interval'];
        yield 'rate limit zero' => [['redirect' => ['rate_limit' => ['misses' => ['limit' => 0]]]], 'limit'];
        yield 'over limit' => [['redirect' => ['rate_limit' => ['over_limit_hits' => 'drop']]], 'over_limit_hits'];
        yield 'random length' => [['code' => ['random_length' => 5]], 'random_length'];
        yield 'reserved code characters' => [['code' => ['reserved' => ['_admin']]], 'reserved'];
        yield 'reserved code not a string' => [['code' => ['reserved' => [true]]], 'reserved'];
        yield 'quarantine' => [['code' => ['quarantine' => '365 days']], 'quarantine'];
        yield 'quarantine empty duration' => [['code' => ['quarantine' => 'P']], 'quarantine'];
        yield 'self path relative' => [['target' => ['self_paths' => ['s/']]], 'self_paths'];
        yield 'self path not a string' => [['target' => ['self_paths' => [3]]], 'self_paths'];
        yield 'own host' => [['target' => ['own_hosts' => ['https://x.example']]], 'own_hosts'];
        yield 'own host not a string' => [['target' => ['own_hosts' => [false]]], 'own_hosts'];
        yield 'denylist provider empty' => [['target' => ['denylist' => ['provider' => '']]], 'provider'];
        yield 'denylist provider not a string' => [['target' => ['denylist' => ['provider' => 7]]], 'provider'];
        yield 'max length' => [['target' => ['max_length' => 4096]], 'max_length'];
        yield 'write mode' => [['click_log' => ['write' => 'later']], 'write'];
        yield 'ipv4 prefix' => [['click_log' => ['ipv4_prefix' => 8]], 'ipv4_prefix'];
        yield 'ipv6 prefix' => [['click_log' => ['ipv6_prefix' => 128]], 'ipv6_prefix'];
        yield 'retention' => [['click_log' => ['retention' => 90]], 'retention'];
        yield 'top n' => [['click_log' => ['rollup' => ['referrer_top_n' => 0]]], 'referrer_top_n'];
        yield 'negative quota' => [['quota' => ['default_limit' => -1]], 'default_limit'];
        yield 'unknown counted kind' => [['quota' => ['counted_kinds' => ['qr']]], 'counted_kinds'];
        yield 'counted kind not a string' => [['quota' => ['counted_kinds' => [5]]], 'counted_kinds'];
        yield 'kind name' => [['kinds' => ['QR' => []]], 'Kind names'];
        yield 'built-in kind not an array' => [['kinds' => ['email' => 'off']], 'email'];
        yield 'page size' => [['ui' => ['page_size' => 5]], 'page_size'];
        yield 'ui prefix equals redirect prefix' => [['ui' => ['path_prefix' => '/s']], 'ui.path_prefix must differ'];
        yield 'tenant route parameter' => [['ui' => ['tenant_route_parameter' => 'organization-uuid']], 'tenant_route_parameter'];
        yield 'tenant route parameter not a string' => [['ui' => ['tenant_route_parameter' => 1]], 'tenant_route_parameter'];
        yield 'table prefix' => [['doctrine' => ['table_prefix' => 'App-']], 'table_prefix'];
        yield 'table prefix too long' => [['doctrine' => ['table_prefix' => 'a' . str_repeat('b', 30)]], 'table_prefix'];
        yield 'table prefix not a string' => [['doctrine' => ['table_prefix' => 1]], 'table_prefix'];
        yield 'mapping' => [['doctrine' => ['mapping' => 'yaml']], 'mapping'];
        yield 'unknown key' => [['enabled' => true], 'enabled'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigurations')]
    public function testInvalidValuesAreRefusedWithThePath(array $config, string $expected): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expected, '/') . '/');

        $this->process($config);
    }

    public function testHelpers(): void
    {
        self::assertTrue(ConfigNodes::isDuration('PT12H'));
        self::assertFalse(ConfigNodes::isDuration('PT'));
        self::assertFalse(ConfigNodes::isDuration(null));
        self::assertTrue(Configuration::isBaseUrl('http://localhost:8231'));
        self::assertSame('', $this->process(['public_base_url' => ''])['public_base_url']);
        self::assertNull(Configuration::unknownKind(['manual'], ['manual' => []]));
        self::assertSame('?', Configuration::unknownKind([null], ['manual' => []]));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }

    /**
     * The value at a nested path of a processed configuration.
     *
     * @param array<mixed> $config
     */
    private function at(array $config, string ...$keys): mixed
    {
        $value = $config;
        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }
}
