<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

/**
 * Loads `nowo_short_link`: validates the strict configuration tree and publishes it as container parameters.
 *
 * S0 registers no service, route, listener or table. The parameters exist so hosts can already reference the redirect
 * and UI prefixes (e.g. a stateless firewall on `%nowo_short_link.redirect_path_prefix%`).
 */
final class NowoShortLinkExtension extends Extension
{
    /** Configuration root and extension alias. */
    public const ALIAS = 'nowo_short_link';

    /**
     * The extension alias, `nowo_short_link`.
     */
    public function getAlias(): string
    {
        return self::ALIAS;
    }

    /**
     * Processes the configuration and sets the `nowo_short_link.*` container parameters.
     *
     * @param array<array-key, mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{redirect: array{path_prefix: string}, ui: array{enabled: bool, path_prefix: string}, doctrine: array{table_prefix: string}} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);
        $container->setParameter(self::ALIAS . '.redirect_path_prefix', $config['redirect']['path_prefix']);
        $container->setParameter(self::ALIAS . '.ui_enabled', $config['ui']['enabled']);
        $container->setParameter(self::ALIAS . '.ui_path_prefix', $config['ui']['path_prefix']);
        $container->setParameter(self::ALIAS . '.table_prefix', $config['doctrine']['table_prefix']);
        $container->setParameter(self::ALIAS . '.config', $config);
    }
}
