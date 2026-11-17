<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\ShortLinkBundle\DependencyInjection\Compiler\TwigPathsPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class TwigPathsPassTest extends TestCase
{
    public function testAddsBundleTwigPath(): void
    {
        $container = new ContainerBuilder();
        $loader    = new Definition();
        $container->setDefinition('twig.loader.native_filesystem', $loader);
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        (new TwigPathsPass())->process($container);

        /** @var non-empty-list<array{0: string, 1: array{0: string, 1: string}}> $calls */
        $calls = $loader->getMethodCalls();
        $last  = $calls[array_key_last($calls)];
        self::assertSame('addPath', $last[0]);
        self::assertSame('NowoShortLinkBundle', $last[1][1]);
    }
}
