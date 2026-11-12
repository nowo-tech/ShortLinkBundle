<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit;

use Nowo\ShortLinkBundle\DependencyInjection\NowoShortLinkExtension;
use Nowo\ShortLinkBundle\NowoShortLinkBundle;
use PHPUnit\Framework\TestCase;

final class BundleTest extends TestCase
{
    public function testTheExtensionAliasIsTheConfigurationRootAndIsBuiltOnce(): void
    {
        $bundle    = new NowoShortLinkBundle();
        $extension = $bundle->getContainerExtension();

        self::assertInstanceOf(NowoShortLinkExtension::class, $extension);
        self::assertSame('nowo_short_link', $extension->getAlias());
        self::assertSame($extension, $bundle->getContainerExtension());
    }
}
