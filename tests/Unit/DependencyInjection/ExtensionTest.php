<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit\DependencyInjection;

use Nowo\ShortLinkBundle\DependencyInjection\NowoShortLinkExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_filter;
use function array_keys;
use function array_values;
use function str_starts_with;

final class ExtensionTest extends TestCase
{
    public function testDefaultsPublishTheParametersAndRegisterNoService(): void
    {
        $container = new ContainerBuilder();
        (new NowoShortLinkExtension())->load([], $container);

        self::assertSame('/s', $container->getParameter('nowo_short_link.redirect_path_prefix'));
        self::assertFalse($container->getParameter('nowo_short_link.ui_enabled'));
        self::assertSame('/short-links', $container->getParameter('nowo_short_link.ui_path_prefix'));
        self::assertSame('', $container->getParameter('nowo_short_link.table_prefix'));
        self::assertIsArray($container->getParameter('nowo_short_link.config'));
        self::assertSame([], array_values(array_filter(
            array_keys($container->getDefinitions()),
            static fn (string $id): bool => !str_starts_with($id, 'service_container'),
        )));
    }

    public function testHostValuesReachTheParameters(): void
    {
        $container = new ContainerBuilder();
        (new NowoShortLinkExtension())->load([
            ['redirect' => ['path_prefix' => '/l'], 'ui' => ['enabled' => true], 'doctrine' => ['table_prefix' => 'app_']],
        ], $container);

        self::assertSame('/l', $container->getParameter('nowo_short_link.redirect_path_prefix'));
        self::assertTrue($container->getParameter('nowo_short_link.ui_enabled'));
        self::assertSame('app_', $container->getParameter('nowo_short_link.table_prefix'));
    }
}
