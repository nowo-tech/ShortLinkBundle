<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\DependencyInjection;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

use function is_array;
use function is_string;
use function parse_url;
use function preg_match;
use function str_starts_with;
use function strtolower;

use const PHP_URL_HOST;

/**
 * Strict configuration tree of `nowo_short_link` (contract v1.0 §4, Beacon answers §17). Every key exists from S0 so
 * hosts can write the final configuration now; S0 itself registers nothing.
 */
final class Configuration implements ConfigurationInterface
{
    /** Built-in link kinds (contract §3.1 `LinkKind`). */
    public const KIND_MANUAL = 'manual';

    /** Generated links (MailKit / Beacon e-mail sends). */
    public const KIND_EMAIL = 'email';

    /**
     * The `nowo_short_link` tree.
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(NowoShortLinkExtension::ALIAS);
        $root        = $treeBuilder->getRootNode();

        $root
            ->children()
                ->scalarNode('public_base_url')->defaultNull()
                    ->info('Absolute http(s) base URL of the short links (e.g. "https://beacon.example" or "%env(DEFAULT_URI)%"); needed outside a request (Messenger, CLI). null or "" = the router request context')
                    ->validate()
                        ->ifTrue(static fn (mixed $value): bool => $value !== null && $value !== '' && !self::isBaseUrl($value))
                        ->thenInvalid('Expected an absolute http(s) URL without query or fragment (e.g. "https://links.example"), got %s.')
                    ->end()
                ->end()
                ->append($this->redirect())
                ->append($this->code())
                ->append($this->target())
                ->append($this->clickLog())
                ->arrayNode('tenancy')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append(ConfigNodes::service('context', 'ShortLinkTenantContextInterface service; null = single tenant "default"'))
                    ->end()
                ->end()
                ->arrayNode('access')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append(ConfigNodes::service('provider', 'ShortLinkAccessInterface service; null = role-based access'))
                        ->scalarNode('view_role')->defaultValue('ROLE_ADMIN')->cannotBeEmpty()->end()
                        ->scalarNode('manage_role')->defaultValue('ROLE_ADMIN')->cannotBeEmpty()->end()
                        ->booleanNode('enforce_in_manager')->defaultTrue()->info('The manager re-checks access unless a command carries the system flag')->end()
                    ->end()
                ->end()
                ->arrayNode('quota')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append(ConfigNodes::service('provider', 'ShortLinkQuotaInterface service; null = default_limit'))
                        ->integerNode('default_limit')->defaultNull()->min(0)->info('Max non-deleted links per tenant and counted kind; null = unlimited')->end()
                        ->arrayNode('counted_kinds')
                            ->info('Kinds the quota applies to (e-mail links are never counted, Beacon Q-B10)')
                            ->defaultValue([self::KIND_MANUAL])
                            ->scalarPrototype()->end()
                        ->end()
                    ->end()
                ->end()
                ->append($this->kinds())
                ->append($this->ui())
                ->append($this->doctrine())
            ->end()
            ->validate()
                ->ifTrue(static function (array $config): bool {
                    /** @var array{redirect: array{path_prefix: string}, ui: array{path_prefix: string}} $typed */
                    $typed = $config;

                    return $typed['redirect']['path_prefix'] === $typed['ui']['path_prefix'];
                })
                ->thenInvalid('ui.path_prefix must differ from redirect.path_prefix, got %s.')
            ->end()
            ->validate()
                ->ifTrue(static function (array $config): bool {
                    /** @var array{quota: array{counted_kinds: list<mixed>}, kinds: array<string, mixed>} $typed */
                    $typed = $config;

                    return self::unknownKind($typed['quota']['counted_kinds'], $typed['kinds']) !== null;
                })
                ->thenInvalid('quota.counted_kinds may only name configured kinds (manual, email and the keys of "kinds"), got %s.')
            ->end()
            ->validate()
                ->ifTrue(static function (array $config): bool {
                    /** @var array{click_log: array{retention: string|null, rollup: array{lag_days: int, retention: string|null}}} $typed */
                    $typed = $config;

                    return self::retentionProblem($typed['click_log']['retention'], $typed['click_log']['rollup']['lag_days'], $typed['click_log']['rollup']['retention']) !== null;
                })
                ->then(static function (array $config): array {
                    /** @var array{click_log: array{retention: string|null, rollup: array{lag_days: int, retention: string|null}}} $typed */
                    $typed = $config;

                    throw new InvalidConfigurationException((string) self::retentionProblem($typed['click_log']['retention'], $typed['click_log']['rollup']['lag_days'], $typed['click_log']['rollup']['retention']));
                })
            ->end();

        return $treeBuilder;
    }

    /**
     * Why the retention settings would lose rolled clicks, or null (audit NY M3): raw clicks must outlive the days the
     * rollup recomputes (`lag_days` + 1), and rollups must outlive raw clicks.
     */
    public static function retentionProblem(?string $retention, int $lagDays, ?string $rollupRetention): ?string
    {
        $raw = $retention === null ? null : self::seconds($retention);
        if ($raw !== null && $raw < ($lagDays + 1) * 86400) {
            return 'click_log.retention must keep raw clicks at least click_log.rollup.lag_days + 1 days, or a recomputed day loses its clicks.';
        }
        if ($rollupRetention !== null && ($raw === null || self::seconds($rollupRetention) < $raw)) {
            return 'click_log.rollup.retention must be at least click_log.retention (rollups outlive raw clicks).';
        }

        return null;
    }

    /**
     * Length of an ISO 8601 duration in seconds, from a fixed reference date.
     */
    public static function seconds(string $duration): int
    {
        $start = new DateTimeImmutable('2000-01-01 00:00:00', new DateTimeZone('UTC'));

        return $start->add(new DateInterval($duration))->getTimestamp() - $start->getTimestamp();
    }

    /**
     * Whether a value is an absolute http(s) URL with a host and no query, fragment or userinfo.
     */
    public static function isBaseUrl(mixed $value): bool
    {
        if (!is_string($value) || preg_match('#^https?://[^\s/?\#@]+(/[^\s?\#]*)?$#iD', $value) !== 1) {
            return false;
        }

        return is_string(parse_url($value, PHP_URL_HOST));
    }

    /**
     * The first counted kind that is not a configured kind, or null.
     *
     * @param list<mixed> $counted
     * @param array<string, mixed> $kinds
     */
    public static function unknownKind(array $counted, array $kinds): ?string
    {
        foreach ($counted as $kind) {
            if (!is_string($kind) || !isset($kinds[$kind])) {
                return is_string($kind) ? $kind : '?';
            }
        }

        return null;
    }

    /**
     * Adds the built-in kinds and fills the flags a host entry leaves out with the built-in defaults (an `email` entry
     * that only sets `show_in_ui` keeps `allow_custom_code: false` and `counted: false`).
     *
     * @param array<mixed> $kinds
     *
     * @return array<mixed>
     */
    public static function withBuiltInKinds(array $kinds): array
    {
        $builtIn = [
            self::KIND_MANUAL => ['allow_custom_code' => true, 'show_in_ui' => true, 'counted' => true],
            self::KIND_EMAIL  => ['allow_custom_code' => false, 'show_in_ui' => false, 'counted' => false],
        ];
        foreach ($builtIn as $name => $flags) {
            $host         = $kinds[$name] ?? [];
            $kinds[$name] = is_array($host) ? $host + $flags : $host;
        }

        return $kinds;
    }

    private function redirect(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('redirect');
        $node->addDefaultsIfNotSet()
            ->children()
                ->append(ConfigNodes::path('path_prefix', '/s'))
                ->enumNode('status')->values([302, 307])->defaultValue(302)->info('301 / 308 are refused: browsers cache them forever')->end()
                ->scalarNode('not_found_template')->defaultValue('@NowoShortLink/redirect/not_found.html.twig')->cannotBeEmpty()->end()
                ->arrayNode('rate_limit')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('cache_pool')->defaultValue('cache.app')->cannotBeEmpty()
                            ->info('Fast shared pool (Redis / APCu); a database or filesystem pool is unsuitable (contract §7.4)')
                        ->end()
                        ->integerNode('ipv6_prefix')->defaultValue(64)->min(56)->max(64)
                            ->info('IPv6 clients are limited per network of this many bits (IPv4 per address)')
                        ->end()
                        ->append(ConfigNodes::rate('misses', 20, '1 minute'))
                        ->append(ConfigNodes::rate('hits', 120, '1 minute'))
                        ->enumNode('over_limit_hits')->values(['redirect_unlogged', 'block'])->defaultValue('redirect_unlogged')
                            ->info('A valid link over the per-IP hit limit: redirect without logging (Q-O6) or answer 429')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private function code(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('code');
        $node->addDefaultsIfNotSet()
            ->children()
                ->integerNode('random_length')->defaultValue(7)->min(6)->max(16)->end()
                ->booleanNode('allow_custom')->defaultTrue()->end()
                ->arrayNode('reserved')
                    ->info('Codes nobody may take, added to the built-in list (case-insensitive; Beacon passes its route segments and brand words)')
                    ->scalarPrototype()
                        ->beforeNormalization()
                            ->ifString()
                            ->then(static fn (string $value): string => strtolower($value))
                        ->end()
                        ->validate()
                            ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match('/^[a-z0-9-]{1,32}$/D', $value) !== 1)
                            ->thenInvalid('Expected a reserved code of 1 to 32 characters [a-z0-9-], got %s.')
                        ->end()
                    ->end()
                ->end()
                ->booleanNode('blocklist')->defaultTrue()->info('Generated codes skip offensive substrings (Q-O9)')->end()
                ->integerNode('max_attempts')->defaultValue(5)->min(1)->max(20)->info('Collisions before the length grows by one')->end()
                ->append(ConfigNodes::duration('quarantine', 'P365D', 'Deleted codes stay taken for this long (Q-O7); null = forever'))
            ->end();

        return $node;
    }

    private function target(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('target');
        $node->addDefaultsIfNotSet()
            ->children()
                ->integerNode('max_length')->defaultValue(2048)->min(64)->max(2048)->end()
                ->scalarNode('guard')->defaultValue('auto')->cannotBeEmpty()
                    ->info('auto (basic + OutboundUrlGuard), basic (literal checks only, warned) or a TargetGuardInterface service id')
                ->end()
                ->integerNode('recheck_max_unresolvable_percent')->defaultValue(20)->min(0)->max(100)
                    ->info('The recheck aborts when more than this share of the checked targets cannot be resolved (DNS outage); 0 = abort on any')
                ->end()
                ->integerNode('recheck_min_sample')->defaultValue(20)->min(0)->max(100000)
                    ->info('The recheck abort threshold applies once at least this many links were checked')
                ->end()
                ->booleanNode('save_resolve_dns')->defaultTrue()->info('DNS check on create / update / enable / recheck (never on the redirect)')->end()
                ->arrayNode('self_paths')
                    ->info('Targets on own_hosts under these paths are refused (no redirect chains)')
                    ->defaultValue(['/s/'])
                    ->scalarPrototype()
                        ->validate()
                            ->ifTrue(static fn (mixed $value): bool => !is_string($value) || !str_starts_with($value, '/'))
                            ->thenInvalid('Expected an absolute path such as "/s/", got %s.')
                        ->end()
                    ->end()
                ->end()
                ->append(ConfigNodes::hosts('own_hosts', 'Hosts treated as this application for self_paths'))
                ->arrayNode('denylist')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append(ConfigNodes::hosts('hosts', 'Default TargetDenylistInterface: exact host or ".suffix"'))
                        ->append(ConfigNodes::service('provider', 'TargetDenylistInterface service; null = the hosts list'))
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private function clickLog(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('click_log');
        $node->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->enumNode('write')->values(['terminate', 'messenger', 'sync'])->defaultValue('terminate')
                    ->info('terminate: after the response (Q-O11); messenger: RecordShortLinkClick message; sync: before answering')
                ->end()
                ->arrayNode('store')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('ip_prefix')->defaultTrue()->end()
                        ->booleanNode('user_agent_family')->defaultTrue()->end()
                        ->booleanNode('referrer_host')->defaultTrue()->end()
                        ->booleanNode('country')->defaultTrue()->end()
                    ->end()
                ->end()
                ->integerNode('ipv4_prefix')->defaultValue(24)->min(16)->max(32)->end()
                ->integerNode('ipv6_prefix')->defaultValue(48)->min(32)->max(64)->end()
                ->append(ConfigNodes::service('country_resolver', 'ClickCountryResolverInterface service (local lookup only); null = no country'))
                ->booleanNode('honour_gpc')->defaultTrue()->info('Sec-GPC: 1 → the click is counted, nothing else stored (Q-O10)')->end()
                ->append(ConfigNodes::duration('retention', 'P90D', 'Raw click rows; null = keep (not recommended)'))
                ->arrayNode('rollup')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('lag_days')->defaultValue(2)->min(0)->max(30)->end()
                        ->integerNode('referrer_top_n')->defaultValue(50)->min(1)->max(500)->end()
                        ->append(ConfigNodes::duration('retention', null, 'Daily aggregates (no personal data); null = forever'))
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private function kinds(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('kinds');
        $node->info('Link kinds: manual and email are built in; add yours (^[a-z][a-z0-9_]{0,31}$)')
            ->useAttributeAsKey('name')
            ->defaultValue([
                self::KIND_MANUAL => ['allow_custom_code' => true, 'show_in_ui' => true, 'counted' => true],
                self::KIND_EMAIL  => ['allow_custom_code' => false, 'show_in_ui' => false, 'counted' => false],
            ])
            ->beforeNormalization()
                ->ifArray()
                ->then(static fn (array $kinds): array => self::withBuiltInKinds($kinds))
            ->end()
            ->validate()
                ->ifTrue(static function (array $kinds): bool {
                    foreach ($kinds as $name => $_flags) {
                        if (preg_match(ConfigNodes::KIND_PATTERN, (string) $name) !== 1) {
                            return true;
                        }
                    }

                    return false;
                })
                ->thenInvalid('Kind names must match ^[a-z][a-z0-9_]{0,31}$, got %s.')
            ->end()
            ->arrayPrototype()
                ->addDefaultsIfNotSet()
                ->children()
                    ->booleanNode('allow_custom_code')->defaultTrue()->end()
                    ->booleanNode('show_in_ui')->defaultTrue()->end()
                    ->booleanNode('counted')->defaultTrue()->info('Also needs the kind in quota.counted_kinds')->end()
                ->end()
            ->end();

        return $node;
    }

    private function ui(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('ui');
        $node->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultFalse()->info('Management pages (opt-in); the host imports the UI routes under its own prefix')->end()
                ->append(ConfigNodes::path('path_prefix', '/short-links'))
                ->scalarNode('tenant_route_parameter')->defaultNull()
                    ->info('Route parameter of the host prefix kept when the UI builds its links (Beacon: "uuid" in /organizations/{uuid}/short-links)')
                    ->validate()
                        ->ifTrue(static fn (mixed $value): bool => $value !== null && (!is_string($value) || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $value) !== 1))
                        ->thenInvalid('Expected a route parameter name such as "uuid", got %s.')
                    ->end()
                ->end()
                ->integerNode('page_size')->defaultValue(25)->min(10)->max(100)->end()
                ->integerNode('stats_default_days')->defaultValue(30)->min(1)->max(366)->end()
                ->integerNode('csv_max_days')->defaultValue(366)->min(1)->max(366)->end()
            ->end();

        return $node;
    }

    private function doctrine(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('doctrine');
        $node->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('entity_manager')->defaultNull()->info('null = the default entity manager; a dedicated one is recommended for DDD hosts')->end()
                ->scalarNode('connection')->defaultNull()->info('DBAL connection of the click tables; null = the entity manager\'s')->end()
                ->scalarNode('table_prefix')->defaultValue('')
                    ->info('Prefix of the bundle tables and indexes (REQ-ORM-002), at most 30 characters')
                    ->validate()
                        ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match('/^(?:[a-z][a-z0-9_]{0,29})?$/D', $value) !== 1)
                        ->thenInvalid('Expected "" or up to 30 characters [a-z0-9_] starting with a letter, got %s.')
                    ->end()
                ->end()
                ->enumNode('mapping')->values(['xml', 'attribute'])->defaultValue('xml')->info('XML (default) or the attribute twin of the same entity')->end()
            ->end();

        return $node;
    }
}
