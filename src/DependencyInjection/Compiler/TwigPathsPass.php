<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function dirname;
use function is_dir;
use function is_string;
use function rtrim;

/**
 * Registers bundle Twig paths so application overrides win (REQ-TWIG-001/002; the legacy `@NowoShortLink` namespace stays registered by Symfony).
 */
final class TwigPathsPass implements CompilerPassInterface
{
    private const TWIG_NAMESPACE = 'NowoShortLinkBundle';

    public function process(ContainerBuilder $container): void
    {
        $loaderId = $this->getNativeLoaderServiceId($container);
        if ($loaderId === null) {
            return;
        }

        $viewsPath  = dirname(__DIR__, 2) . '/Resources/views';
        $definition = $container->getDefinition($loaderId);

        if ($container->hasParameter('kernel.project_dir')) {
            $projectDirParam = $container->getParameter('kernel.project_dir');
            if (is_string($projectDirParam)) {
                $projectDir   = rtrim($projectDirParam, '/\\');
                $overridePath = $projectDir . '/templates/bundles/NowoShortLinkBundle';
                if (is_dir($overridePath)) {
                    // @igor-ignore - Compile-time DI definition wiring; runs once at container build
                    $definition->addMethodCall('prependPath', [$overridePath, self::TWIG_NAMESPACE]);
                }
            }
        }

        // @igor-ignore - Compile-time DI definition wiring; runs once at container build

        // @igor-ignore - Compile-time DI definition wiring; runs once at container build
        $definition->addMethodCall('addPath', [$viewsPath, self::TWIG_NAMESPACE]);
    }

    private function getNativeLoaderServiceId(ContainerBuilder $container): ?string
    {
        if ($container->hasAlias('twig.loader.native')) {
            $resolved = $this->resolveDefinitionId($container, (string) $container->getAlias('twig.loader.native'));
            if ($resolved !== null) {
                return $resolved;
            }
        }
        if ($container->hasDefinition('twig.loader.native')) {
            return 'twig.loader.native';
        }
        if ($container->hasDefinition('twig.loader.native_filesystem')) {
            return 'twig.loader.native_filesystem';
        }
        if ($container->hasDefinition('twig.loader.filesystem')) {
            return 'twig.loader.filesystem';
        }

        return null;
    }

    private function resolveDefinitionId(ContainerBuilder $container, string $id): ?string
    {
        for ($i = 0; $i < 32 && $container->hasAlias($id); ++$i) {
            $id = (string) $container->getAlias($id);
        }

        return $container->hasDefinition($id) ? $id : null;
    }
}
