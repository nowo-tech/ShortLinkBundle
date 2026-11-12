<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\ScalarNodeDefinition;

use function is_string;
use function preg_match;
use function strtolower;

/**
 * Reusable node definitions of the strict configuration tree (REQ-SF-006): every error names the path and the
 * allowed values.
 */
final class ConfigNodes
{
    /** Kind names and dimension keys: `^[a-z][a-z0-9_]{0,31}$` (contract §3.1 `LinkKind`). */
    public const KIND_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/D';

    /** URL path prefix: absolute, no trailing slash, not `/` alone, e.g. `/s` or `/admin/short-links`. */
    public const PATH_PATTERN = '#^(/[A-Za-z0-9._~-]+)+$#D';

    /**
     * An absolute URL path without a trailing slash (never `/` alone).
     */
    public static function path(string $name, string $default): ScalarNodeDefinition
    {
        $node = new ScalarNodeDefinition($name);
        $node->defaultValue($default)
            ->validate()
                ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match(self::PATH_PATTERN, $value) !== 1)
                ->thenInvalid('Expected an absolute path without a trailing slash and other than "/" (e.g. "/s"), got %s.')
            ->end();

        return $node;
    }

    /**
     * An optional service id (null = the bundle default).
     */
    public static function service(string $name, string $info = 'Service id; null = the bundle default'): ScalarNodeDefinition
    {
        $node = new ScalarNodeDefinition($name);
        $node->defaultNull()->info($info)
            ->validate()
                ->ifTrue(static fn (mixed $value): bool => $value !== null && (!is_string($value) || $value === ''))
                ->thenInvalid('Expected a service id or null, got %s.')
            ->end();

        return $node;
    }

    /**
     * An optional ISO 8601 duration (`P90D`, `P1Y`); null = keep forever.
     */
    public static function duration(string $name, ?string $default, string $info): ScalarNodeDefinition
    {
        $node = new ScalarNodeDefinition($name);
        $node->defaultValue($default)->info($info)
            ->validate()
                ->ifTrue(static fn (mixed $value): bool => $value !== null && !self::isDuration($value))
                ->thenInvalid('Expected an ISO 8601 duration such as "P90D" or null, got %s.')
            ->end();

        return $node;
    }

    /**
     * One rate-limit window: `{limit, interval}` (symfony/rate-limiter fixed window).
     */
    public static function rate(string $name, int $limit, string $interval): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition($name);
        $node->addDefaultsIfNotSet()
            ->children()
                ->integerNode('limit')->defaultValue($limit)->min(1)->max(100000)->end()
                ->scalarNode('interval')->defaultValue($interval)->cannotBeEmpty()
                    ->validate()
                        ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match('/^\d+ (second|minute|hour|day)s?$/D', $value) !== 1)
                        ->thenInvalid('Expected an interval such as "1 minute" or "15 minutes", got %s.')
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    /**
     * A list of host names (lowercased; exact host or `.suffix`).
     */
    public static function hosts(string $name, string $info): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition($name);
        $node->info($info)
            ->scalarPrototype()
                ->validate()
                    ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match('/^\.?[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/D', $value) !== 1)
                    ->thenInvalid('Expected a host name ("example.com") or a suffix (".example.com"), got %s.')
                ->end()
                ->beforeNormalization()
                    ->ifString()
                    ->then(static fn (string $value): string => strtolower($value))
                ->end()
            ->end();

        return $node;
    }

    /**
     * Whether a value is an ISO 8601 duration that `DateInterval` accepts (`P90D`, `P1Y`, `PT12H`).
     */
    public static function isDuration(mixed $value): bool
    {
        return is_string($value) && preg_match('/^P(?!$)(\d+Y)?(\d+M)?(\d+W)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+S)?)?$/D', $value) === 1;
    }
}
