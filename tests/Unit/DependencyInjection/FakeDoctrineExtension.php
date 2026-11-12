<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

/**
 * Stands in for DoctrineBundle's extension in prepend tests.
 */
final class FakeDoctrineExtension extends Extension
{
    public function getAlias(): string
    {
        return 'doctrine';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
    }
}
