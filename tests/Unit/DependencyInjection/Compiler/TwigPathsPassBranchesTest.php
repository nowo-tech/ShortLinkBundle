<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\ShortLinkBundle\DependencyInjection\Compiler\TwigPathsPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

use function dirname;

final class TwigPathsPassBranchesTest extends TestCase
{
    public function testUsesNativeLoaderDefinition(): void
    {
        $container = new ContainerBuilder();
        $loader    = new Definition();
        $container->setDefinition('twig.loader.native', $loader);

        (new TwigPathsPass())->process($container);

        /** @var list<array{0: string, 1: array<int, mixed>}> $calls */
        $calls = $loader->getMethodCalls();
        self::assertSame('addPath', $calls[0][0]);
    }

    public function testResolvesChainedAlias(): void
    {
        $container = new ContainerBuilder();
        $loader    = new Definition();
        $container->setDefinition('twig.loader.native_filesystem', $loader);
        $container->setAlias('twig.loader.mid', 'twig.loader.native_filesystem');
        $container->setAlias('twig.loader.native', 'twig.loader.mid');

        (new TwigPathsPass())->process($container);

        self::assertNotEmpty($loader->getMethodCalls());
    }

    public function testReturnsEarlyWithoutTwigLoader(): void
    {
        $container = new ContainerBuilder();
        (new TwigPathsPass())->process($container);
        self::assertFalse($container->hasDefinition('twig.loader.filesystem'));
    }

    public function testResolvesAliasAndPrependsOverridePath(): void
    {
        $override = sys_get_temp_dir() . '/mk_twig_override_' . uniqid('', true);
        mkdir($override, 0777, true);

        $container = new ContainerBuilder();
        $loader    = new Definition();
        $container->setDefinition('twig.loader.native_filesystem', $loader);
        $container->setAlias('twig.loader.native', 'twig.loader.native_filesystem');
        $container->setParameter('kernel.project_dir', dirname($override, 2));
        // override path must be {project}/templates/bundles/NowoShortLinkBundle
        $project      = sys_get_temp_dir() . '/mk_proj_' . uniqid('', true);
        $overridePath = $project . '/templates/bundles/NowoShortLinkBundle';
        mkdir($overridePath, 0777, true);
        $container->setParameter('kernel.project_dir', $project);

        (new TwigPathsPass())->process($container);

        $calls = array_column($loader->getMethodCalls(), 0);
        self::assertContains('prependPath', $calls);
        self::assertContains('addPath', $calls);

        // cleanup
        @rmdir($overridePath);
        @rmdir(dirname($overridePath));
        @rmdir(dirname($overridePath, 2));
        @rmdir($project);
        @rmdir($override);
    }

    public function testUsesFilesystemLoaderFallback(): void
    {
        $container = new ContainerBuilder();
        $loader    = new Definition();
        $container->setDefinition('twig.loader.filesystem', $loader);

        (new TwigPathsPass())->process($container);

        /** @var list<array{0: string, 1: array<int, mixed>}> $calls */
        $calls = $loader->getMethodCalls();
        self::assertSame('addPath', $calls[0][0]);
    }
}
