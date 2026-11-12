<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\DependencyInjection;

use LogicException;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Nowo\ShortLinkBundle\Application\Click\ClickRecorder;
use Nowo\ShortLinkBundle\Application\Click\RedirectResolver;
use Nowo\ShortLinkBundle\Application\CodeGenerator;
use Nowo\ShortLinkBundle\Application\Default\BuiltInCodeBlocklist;
use Nowo\ShortLinkBundle\Application\Default\BuiltInUserAgentClassifier;
use Nowo\ShortLinkBundle\Application\Default\ConfigDenylist;
use Nowo\ShortLinkBundle\Application\Default\ConfigQuota;
use Nowo\ShortLinkBundle\Application\Default\ConfigReservedCodes;
use Nowo\ShortLinkBundle\Application\Default\NullCountryResolver;
use Nowo\ShortLinkBundle\Application\Default\SingleTenantContext;
use Nowo\ShortLinkBundle\Application\Guard\ChainTargetGuard;
use Nowo\ShortLinkBundle\Application\Guard\DenylistGuard;
use Nowo\ShortLinkBundle\Application\Guard\SelfLoopGuard;
use Nowo\ShortLinkBundle\Application\ManagerSettings;
use Nowo\ShortLinkBundle\Application\ShortLinkManager;
use Nowo\ShortLinkBundle\Application\ShortUrlBuilderInterface;
use Nowo\ShortLinkBundle\Application\Store\ClickStoreInterface;
use Nowo\ShortLinkBundle\Application\Store\ShortLinkStoreInterface;
use Nowo\ShortLinkBundle\Domain\Model\ClickWriteMode;
use Nowo\ShortLinkBundle\Domain\Port\ClickCountryResolverInterface;
use Nowo\ShortLinkBundle\Domain\Port\CodeBlocklistInterface;
use Nowo\ShortLinkBundle\Domain\Port\ReservedCodeProviderInterface;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkAccessInterface;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkIdGeneratorInterface;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkQuotaInterface;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkTenantContextInterface;
use Nowo\ShortLinkBundle\Domain\Port\TargetDenylistInterface;
use Nowo\ShortLinkBundle\Domain\Port\TargetGuardInterface;
use Nowo\ShortLinkBundle\Domain\Port\TenantLockInterface;
use Nowo\ShortLinkBundle\Domain\Port\UserAgentClassifierInterface;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkEraserInterface;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkManagerInterface;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkStatsReaderInterface;
use Nowo\ShortLinkBundle\Infrastructure\Console\PurgeCommand;
use Nowo\ShortLinkBundle\Infrastructure\Console\RecheckCommand;
use Nowo\ShortLinkBundle\Infrastructure\Console\RollupCommand;
use Nowo\ShortLinkBundle\Infrastructure\Doctrine\ClickTablesSchemaListener;
use Nowo\ShortLinkBundle\Infrastructure\Doctrine\ShortLinkMetadataListener;
use Nowo\ShortLinkBundle\Infrastructure\Guard\OutboundUrlGuardBridge;
use Nowo\ShortLinkBundle\Infrastructure\Http\ClickTerminateListener;
use Nowo\ShortLinkBundle\Infrastructure\Http\RedirectController;
use Nowo\ShortLinkBundle\Infrastructure\Lock\NoTenantLock;
use Nowo\ShortLinkBundle\Infrastructure\Messenger\RecordShortLinkClickHandler;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\AfterCommitMiddleware;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\AfterCommitQueueResetListener;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\ClickTables;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\DbalClickMaintenance;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\DbalClickStore;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\DbalEraser;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\DbalShortLinkStore;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\DbalStatsReader;
use Nowo\ShortLinkBundle\Infrastructure\Routing\ShortUrlBuilder;
use Nowo\ShortLinkBundle\Infrastructure\Security\RoleBasedAccess;
use Nowo\ShortLinkBundle\Infrastructure\Uid\UuidV7IdGenerator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

use function array_unique;
use function array_values;
use function is_array;
use function is_string;

/**
 * Loads `nowo_short_link`: validates the strict configuration tree, publishes the container parameters and registers
 * the S1 services (manager, store, ports with their defaults, guards, Doctrine listeners). Every port is a public alias
 * so hosts can replace or decorate it.
 */
final class NowoShortLinkExtension extends Extension implements PrependExtensionInterface
{
    /** Configuration root and extension alias. */
    public const ALIAS = 'nowo_short_link';

    /** Doctrine mapping name of the bundle's entity. */
    public const MAPPING_NAME = 'NowoShortLinkBundle';

    /**
     * The extension alias, `nowo_short_link`.
     */
    public function getAlias(): string
    {
        return self::ALIAS;
    }

    /**
     * Registers the XML mapping (or its attribute twin) on the configured entity manager when the host uses the ORM.
     */
    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('doctrine')) {
            return;
        }
        $hasOrm = false;
        foreach ($container->getExtensionConfig('doctrine') as $doctrine) {
            $hasOrm = $hasOrm || (is_array($doctrine) && isset($doctrine['orm']));
        }
        if (!$hasOrm) {
            return;
        }
        // Only the doctrine subtree is read here: the rest may hold env placeholders that are resolved later.
        $entityManager = 'default';
        $attribute     = false;
        foreach ($container->getExtensionConfig(self::ALIAS) as $config) {
            $doctrine = is_array($config) && is_array($config['doctrine'] ?? null) ? $config['doctrine'] : [];
            if (is_string($doctrine['entity_manager'] ?? null)) {
                $entityManager = $doctrine['entity_manager'];
            }
            if (isset($doctrine['mapping'])) {
                $attribute = $doctrine['mapping'] === 'attribute';
            }
        }
        $mapping = $attribute
            ? ['type' => 'attribute', 'dir' => __DIR__ . '/../Infrastructure/Doctrine/Entity']
            : ['type' => 'xml', 'dir' => __DIR__ . '/../Resources/config/doctrine-mapping'];
        $container->prependExtensionConfig('doctrine', [
            'orm' => ['entity_managers' => [$entityManager => ['mappings' => [
                self::MAPPING_NAME => $mapping + ['prefix' => 'Nowo\ShortLinkBundle\Infrastructure\Doctrine\Entity', 'is_bundle' => false],
            ]]]],
        ]);
    }

    /**
     * Processes the configuration, sets the `nowo_short_link.*` parameters and registers the services.
     *
     * @param array<array-key, mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{public_base_url: string|null, redirect: array{path_prefix: string, status: int, not_found_template: string, rate_limit: array{enabled: bool, cache_pool: string, ipv6_prefix: int, misses: array{limit: int, interval: string}, hits: array{limit: int, interval: string}, over_limit_hits: string}}, code: array{random_length: int, allow_custom: bool, reserved: list<string>, blocklist: bool, max_attempts: int, quarantine: string|null}, target: array{max_length: int, guard: string, recheck_max_unresolvable_percent: int, recheck_min_sample: int, save_resolve_dns: bool, self_paths: list<string>, own_hosts: list<string>, denylist: array{hosts: list<string>, provider: string|null}}, tenancy: array{context: string|null}, access: array{provider: string|null, view_role: string, manage_role: string, enforce_in_manager: bool}, quota: array{provider: string|null, default_limit: int|null, counted_kinds: list<string>}, kinds: array<string, array{allow_custom_code: bool, show_in_ui: bool, counted: bool}>, ui: array{enabled: bool, path_prefix: string}, doctrine: array{entity_manager: string|null, connection: string|null, table_prefix: string}, click_log: array{enabled: bool, write: string, store: array{ip_prefix: bool, user_agent_family: bool, referrer_host: bool, country: bool}, ipv4_prefix: int, ipv6_prefix: int, honour_gpc: bool, country_resolver: string|null, retention: string|null, rollup: array{lag_days: int, referrer_top_n: int, retention: string|null}}} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);
        $container->setParameter(self::ALIAS . '.redirect_path_prefix', $config['redirect']['path_prefix']);
        $container->setParameter(self::ALIAS . '.ui_enabled', $config['ui']['enabled']);
        $container->setParameter(self::ALIAS . '.ui_path_prefix', $config['ui']['path_prefix']);
        $container->setParameter(self::ALIAS . '.table_prefix', $config['doctrine']['table_prefix']);
        $container->setParameter(self::ALIAS . '.config', $config);

        $logger = new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $prefix = $config['doctrine']['table_prefix'];

        // Persistence (DBAL, after-commit events).
        $container->register(AfterCommitQueue::class, AfterCommitQueue::class)
            ->setArguments([$logger])
            ->addTag('kernel.reset', ['method' => 'reset']);
        $reset = $container->register(AfterCommitQueueResetListener::class, AfterCommitQueueResetListener::class)
            ->setArguments([new Reference(AfterCommitQueue::class)])
            ->addTag('kernel.event_listener', ['event' => 'kernel.finish_request', 'method' => 'onFinishRequest', 'priority' => -1024])
            ->addTag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'onBoundary', 'priority' => -1024])
            ->addTag('kernel.event_listener', ['event' => 'console.terminate', 'method' => 'onBoundary', 'priority' => -1024]);
        foreach (['Symfony\Component\Messenger\Event\WorkerMessageHandledEvent', 'Symfony\Component\Messenger\Event\WorkerMessageFailedEvent'] as $event) {
            $reset->addTag('kernel.event_listener', ['event' => $event, 'method' => 'onBoundary', 'priority' => -1024]);
        }
        $container->register(AfterCommitMiddleware::class, AfterCommitMiddleware::class)
            ->setArguments([new Reference(AfterCommitQueue::class)])
            ->addTag('doctrine.middleware');
        $container->register(ShortLinkMetadataListener::class, ShortLinkMetadataListener::class)
            ->setArguments([$prefix])
            ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata']);
        if ($config['doctrine']['connection'] === null) {
            $container->register(ClickTablesSchemaListener::class, ClickTablesSchemaListener::class)
                ->setArguments([$prefix])
                ->addTag('doctrine.event_listener', ['event' => 'postGenerateSchema']);
        }
        $container->register(DbalShortLinkStore::class, DbalShortLinkStore::class)
            ->setArguments([new Reference('doctrine'), new Reference(AfterCommitQueue::class), $config['doctrine']['entity_manager'], $prefix, $logger]);
        $container->setAlias(ShortLinkStoreInterface::class, DbalShortLinkStore::class);

        if ($config['tenancy']['context'] !== null && $config['access']['provider'] === null) {
            throw new LogicException('nowo_short_link.tenancy.context is set but access.provider is not: the default role-based access grants a role in every tenant. Set access.provider to an access port that checks the tenant.');
        }

        // Ports (public aliases: hosts replace them through configuration or decorate them).
        $this->port($container, ShortLinkTenantContextInterface::class, $config['tenancy']['context'], new Definition(SingleTenantContext::class));
        $this->port($container, ShortLinkAccessInterface::class, $config['access']['provider'], (new Definition(RoleBasedAccess::class))->setArguments([
            new Reference('security.authorization_checker', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $config['access']['view_role'],
            $config['access']['manage_role'],
        ]));
        $this->port($container, ShortLinkQuotaInterface::class, $config['quota']['provider'], (new Definition(ConfigQuota::class))->setArguments([$config['quota']['default_limit']]));
        $this->port($container, TargetDenylistInterface::class, $config['target']['denylist']['provider'], (new Definition(ConfigDenylist::class))->setArguments([$config['target']['denylist']['hosts']]));
        $this->port($container, ReservedCodeProviderInterface::class, null, (new Definition(ConfigReservedCodes::class))->setArguments([$config['code']['reserved']]));
        $this->port($container, CodeBlocklistInterface::class, null, new Definition(BuiltInCodeBlocklist::class));
        $this->port($container, ShortLinkIdGeneratorInterface::class, null, new Definition(UuidV7IdGenerator::class));
        // TenantLockPass points this alias at the symfony/lock implementation when a lock factory exists.
        $this->port($container, TenantLockInterface::class, null, new Definition(NoTenantLock::class));

        // Target guard: self chain + OutboundUrlGuard (never private; DNS on save / recheck) + denylist.
        $guard = $config['target']['guard'];
        if ($guard === 'auto' || $guard === 'basic') {
            $container->register(self::ALIAS . '.outbound_url_guard', OutboundUrlGuard::class)->setArguments([false, true]);
            $container->register(ChainTargetGuard::class, ChainTargetGuard::class)->setArguments([[
                (new Definition(SelfLoopGuard::class))->setArguments([$config['target']['own_hosts'], array_values(array_unique([...$config['target']['self_paths'], $config['redirect']['path_prefix'] . '/'])), $config['public_base_url']]),
                (new Definition(OutboundUrlGuardBridge::class))->setArguments([new Reference(self::ALIAS . '.outbound_url_guard'), $guard === 'auto' && $config['target']['save_resolve_dns']]),
                (new Definition(DenylistGuard::class))->setArguments([new Reference(TargetDenylistInterface::class)]),
            ]]);
            $container->setAlias(TargetGuardInterface::class, ChainTargetGuard::class)->setPublic(true);
        } else {
            $container->setAlias(TargetGuardInterface::class, $guard)->setPublic(true);
        }

        // Application services.
        $container->register(CodeGenerator::class, CodeGenerator::class)->setArguments([
            new Reference(ReservedCodeProviderInterface::class),
            $config['code']['blocklist'] ? new Reference(CodeBlocklistInterface::class) : null,
        ]);
        $container->register(ShortUrlBuilder::class, ShortUrlBuilder::class)->setArguments([
            $config['public_base_url'],
            $config['redirect']['path_prefix'],
            new Reference('router.request_context', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]);
        $container->setAlias(ShortUrlBuilderInterface::class, ShortUrlBuilder::class);
        $container->register(ManagerSettings::class, ManagerSettings::class)->setArguments([
            $config['code']['random_length'],
            $config['code']['allow_custom'],
            $config['code']['max_attempts'],
            $config['target']['max_length'],
            $config['access']['enforce_in_manager'],
            $config['kinds'],
            $config['quota']['counted_kinds'],
        ]);
        $container->register(ShortLinkManager::class, ShortLinkManager::class)->setArguments([
            '$store'    => new Reference(ShortLinkStoreInterface::class),
            '$codes'    => new Reference(CodeGenerator::class),
            '$reserved' => new Reference(ReservedCodeProviderInterface::class),
            '$guard'    => new Reference(TargetGuardInterface::class),
            '$access'   => new Reference(ShortLinkAccessInterface::class),
            '$quota'    => new Reference(ShortLinkQuotaInterface::class),
            '$lock'     => new Reference(TenantLockInterface::class),
            '$ids'      => new Reference(ShortLinkIdGeneratorInterface::class),
            '$urls'     => new Reference(ShortUrlBuilderInterface::class),
            '$events'   => new Reference('event_dispatcher'),
            '$settings' => new Reference(ManagerSettings::class),
            '$clock'    => new Reference('clock', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            '$logger'   => $logger,
        ]);
        $container->setAlias(ShortLinkManagerInterface::class, ShortLinkManager::class)->setPublic(true);

        $this->registerRedirect($container, $config, $logger);
        $this->registerStats($container, $config);
    }

    /**
     * S3: statistics, retention commands, recheck and GDPR erasure.
     *
     * @param array{code: array{quarantine: string|null}, target: array{recheck_max_unresolvable_percent: int, recheck_min_sample: int}, click_log: array{retention: string|null, rollup: array{lag_days: int, referrer_top_n: int, retention: string|null}}, doctrine: array{entity_manager: string|null, connection: string|null, table_prefix: string}} $config
     */
    private function registerStats(ContainerBuilder $container, array $config): void
    {
        $clock = new Reference('clock', ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $container->register(ClickTables::class, ClickTables::class)
            ->setArguments([new Reference('doctrine'), $config['doctrine']['connection'], $config['doctrine']['entity_manager'], $config['doctrine']['table_prefix']]);
        $container->register(DbalClickMaintenance::class, DbalClickMaintenance::class)->setArguments([new Reference(ClickTables::class), new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE)]);
        $container->register(DbalStatsReader::class, DbalStatsReader::class)->setArguments([new Reference(ClickTables::class), new Reference(DbalClickMaintenance::class)]);
        $container->setAlias(ShortLinkStatsReaderInterface::class, DbalStatsReader::class)->setPublic(true);
        $container->register(DbalEraser::class, DbalEraser::class)->setArguments([new Reference(ClickTables::class), $clock]);
        $container->setAlias(ShortLinkEraserInterface::class, DbalEraser::class)->setPublic(true);

        $container->register(RollupCommand::class, RollupCommand::class)->setAutoconfigured(true)->setPublic(true)
            ->setArguments([new Reference(DbalClickMaintenance::class), $config['click_log']['rollup']['lag_days'], $config['click_log']['rollup']['referrer_top_n'], $clock, $config['click_log']['retention'], new Reference('lock.factory', ContainerInterface::NULL_ON_INVALID_REFERENCE)]);
        $container->register(PurgeCommand::class, PurgeCommand::class)->setAutoconfigured(true)->setPublic(true)
            ->setArguments([new Reference(DbalClickMaintenance::class), $config['click_log']['retention'], $config['code']['quarantine'], $config['click_log']['rollup']['retention'], $clock]);
        $container->register(RecheckCommand::class, RecheckCommand::class)->setAutoconfigured(true)->setPublic(true)
            ->setArguments([new Reference(ShortLinkStoreInterface::class), new Reference(TargetGuardInterface::class), new Reference('event_dispatcher'), $clock, $config['target']['recheck_max_unresolvable_percent'], new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE), $config['target']['recheck_min_sample']]);
    }

    /**
     * S2: the public redirect, its limiters and the click log.
     *
     * @param array{redirect: array{path_prefix: string, status: int, not_found_template: string, rate_limit: array{enabled: bool, cache_pool: string, ipv6_prefix: int, misses: array{limit: int, interval: string}, hits: array{limit: int, interval: string}, over_limit_hits: string}}, click_log: array{enabled: bool, write: string, store: array{ip_prefix: bool, user_agent_family: bool, referrer_host: bool, country: bool}, ipv4_prefix: int, ipv6_prefix: int, honour_gpc: bool, country_resolver: string|null}, doctrine: array{entity_manager: string|null, connection: string|null, table_prefix: string}} $config
     */
    private function registerRedirect(ContainerBuilder $container, array $config, Reference $logger): void
    {
        $clock    = new Reference('clock', ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $events   = new Reference('event_dispatcher');
        $redirect = $config['redirect'];
        $clicks   = $config['click_log'];

        $this->port($container, UserAgentClassifierInterface::class, null, new Definition(BuiltInUserAgentClassifier::class));
        $this->port($container, ClickCountryResolverInterface::class, $clicks['country_resolver'], new Definition(NullCountryResolver::class));
        $container->register(DbalClickStore::class, DbalClickStore::class)
            ->setArguments([new Reference('doctrine'), $config['doctrine']['connection'], $config['doctrine']['entity_manager'], $config['doctrine']['table_prefix']]);
        $container->setAlias(ClickStoreInterface::class, DbalClickStore::class);
        $container->register(ClickRecorder::class, ClickRecorder::class)->setArguments([
            '$clicks'    => new Reference(ClickStoreInterface::class),
            '$countries' => new Reference(ClickCountryResolverInterface::class),
            '$events'    => $events,
            '$store'     => $clicks['store'],
            '$honourGpc' => $clicks['honour_gpc'],
            '$logger'    => $logger,
            '$links'     => new Reference(ShortLinkStoreInterface::class),
        ]);
        $container->register(RedirectResolver::class, RedirectResolver::class)->setArguments([
            '$store' => new Reference(ShortLinkStoreInterface::class),
            '$guard' => new Reference(TargetGuardInterface::class),
            '$clock' => $clock,
        ]);

        $limiters = ['misses' => null, 'hits' => null];
        if ($redirect['rate_limit']['enabled']) {
            $storage = (new Definition(CacheStorage::class))->setArguments([new Reference($redirect['rate_limit']['cache_pool'])]);
            foreach (['misses', 'hits'] as $name) {
                $id = self::ALIAS . '.redirect_limiter.' . $name;
                $container->register($id, RateLimiterFactory::class)->setArguments([[
                    'id'       => self::ALIAS . '_' . $name,
                    'policy'   => 'fixed_window',
                    'limit'    => $redirect['rate_limit'][$name]['limit'],
                    'interval' => $redirect['rate_limit'][$name]['interval'],
                ], $storage]);
                $limiters[$name] = new Reference($id);
            }
        }

        $mode = $clicks['enabled'] ? ClickWriteMode::from($clicks['write']) : null;
        $container->register(RedirectController::class, RedirectController::class)
            ->setPublic(true)
            ->addTag('controller.service_arguments')
            ->setArguments([
                '$resolver'          => new Reference(RedirectResolver::class),
                '$classifier'        => new Reference(UserAgentClassifierInterface::class),
                '$recorder'          => new Reference(ClickRecorder::class),
                '$twig'              => new Reference('twig'),
                '$misses'            => $limiters['misses'],
                '$hits'              => $limiters['hits'],
                '$blockOverHits'     => $redirect['rate_limit']['over_limit_hits'] === 'block',
                '$writeMode'         => $mode,
                '$bus'               => $mode === ClickWriteMode::Messenger ? new Reference('messenger.default_bus') : null,
                '$status'            => $redirect['status'],
                '$notFoundTemplate'  => $redirect['not_found_template'],
                '$ipv4Prefix'        => $clicks['ipv4_prefix'],
                '$ipv6Prefix'        => $clicks['ipv6_prefix'],
                '$clock'             => $clock,
                '$logger'            => $logger,
                '$limiterIpv6Prefix' => $redirect['rate_limit']['ipv6_prefix'],
                '$honourGpc'         => $clicks['honour_gpc'],
            ]);
        // Always registered: it also dispatches the deferred refused-target event after the response.
        $container->register(ClickTerminateListener::class, ClickTerminateListener::class)
            ->setArguments([new Reference(ClickRecorder::class), $events, $logger])
            ->addTag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'onTerminate']);
        if ($mode === ClickWriteMode::Messenger) {
            $container->register(RecordShortLinkClickHandler::class, RecordShortLinkClickHandler::class)
                ->setArguments([new Reference(ClickRecorder::class)])
                ->addTag('messenger.message_handler');
        }
        if ($mode === ClickWriteMode::Messenger && $clicks['store']['country'] && $clicks['country_resolver'] !== null) {
            throw new LogicException('nowo_short_link: a country resolver needs the raw IP, which never travels in a Messenger message. Use click_log.write "terminate" or "sync", or set click_log.store.country to false.');
        }
        $container->setParameter(self::ALIAS . '.click_write_mode', $mode?->value);
    }

    /**
     * Registers the default implementation of a port, or points the port at the host's service.
     */
    private function port(ContainerBuilder $container, string $interface, ?string $hostService, Definition $default): void
    {
        if ($hostService !== null) {
            $container->setAlias($interface, $hostService)->setPublic(true);

            return;
        }
        $id = (string) $default->getClass();
        $container->setDefinition($id, $default);
        $container->setAlias($interface, $id)->setPublic(true);
    }
}
