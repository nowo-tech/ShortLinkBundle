<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\ShortLinkBundle\Application\CodeGenerator;
use Nowo\ShortLinkBundle\Application\Guard\ChainTargetGuard;
use Nowo\ShortLinkBundle\DependencyInjection\Compiler\ShortLinkCompilerPass;
use Nowo\ShortLinkBundle\DependencyInjection\NowoShortLinkExtension;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkAccessInterface;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkQuotaInterface;
use Nowo\ShortLinkBundle\Domain\Port\TargetDenylistInterface;
use Nowo\ShortLinkBundle\Domain\Port\TargetGuardInterface;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkManagerInterface;
use Nowo\ShortLinkBundle\Infrastructure\Doctrine\ClickTablesSchemaListener;
use Nowo\ShortLinkBundle\Infrastructure\Messenger\RecordShortLinkClickHandler;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class ExtensionTest extends TestCase
{
    public function testDefaultsPublishTheParametersAndRegisterTheServices(): void
    {
        $container = new ContainerBuilder();
        (new NowoShortLinkExtension())->load([], $container);

        self::assertSame('/s', $container->getParameter('nowo_short_link.redirect_path_prefix'));
        self::assertFalse($container->getParameter('nowo_short_link.ui_enabled'));
        self::assertSame('/short-links', $container->getParameter('nowo_short_link.ui_path_prefix'));
        self::assertSame('', $container->getParameter('nowo_short_link.table_prefix'));
        self::assertIsArray($container->getParameter('nowo_short_link.config'));
        self::assertTrue($container->getAlias(ShortLinkManagerInterface::class)->isPublic());
        self::assertSame(ChainTargetGuard::class, (string) $container->getAlias(TargetGuardInterface::class));
        self::assertTrue($container->hasDefinition(ClickTablesSchemaListener::class));
        self::assertTrue($this->bridge($container)->getArgument(1), 'auto guard resolves DNS on save');
    }

    public function testHostServicesAndOptionsReplaceTheDefaults(): void
    {
        $container = new ContainerBuilder();
        (new NowoShortLinkExtension())->load([[
            'redirect' => ['path_prefix' => '/l'],
            'ui'       => ['enabled' => true],
            'doctrine' => ['table_prefix' => 'app_', 'connection' => 'clicks'],
            'access'   => ['provider' => 'app.access'],
            'quota'    => ['provider' => 'app.quota'],
            'target'   => ['guard' => 'basic', 'denylist' => ['provider' => 'app.denylist']],
            'code'     => ['blocklist' => false],
        ]], $container);

        self::assertSame('/l', $container->getParameter('nowo_short_link.redirect_path_prefix'));
        self::assertTrue($container->getParameter('nowo_short_link.ui_enabled'));
        self::assertSame('app_', $container->getParameter('nowo_short_link.table_prefix'));
        self::assertSame('app.access', (string) $container->getAlias(ShortLinkAccessInterface::class));
        self::assertSame('app.quota', (string) $container->getAlias(ShortLinkQuotaInterface::class));
        self::assertSame('app.denylist', (string) $container->getAlias(TargetDenylistInterface::class));
        self::assertFalse($container->hasDefinition(ClickTablesSchemaListener::class), 'a separate click connection: the host creates those tables');
        self::assertFalse($this->bridge($container)->getArgument(1), 'basic guard never resolves DNS');
        self::assertNull($container->getDefinition(CodeGenerator::class)->getArgument(1));
    }

    public function testMessengerClicksNeverCarryTheRawIpAndNeedABus(): void
    {
        try {
            (new NowoShortLinkExtension())->load([['click_log' => ['write' => 'messenger', 'country_resolver' => 'app.geo']]], new ContainerBuilder());
            self::fail('A country resolver cannot work in messenger mode.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('raw IP', $exception->getMessage());
        }

        $container = new ContainerBuilder();
        (new NowoShortLinkExtension())->load([['click_log' => ['write' => 'messenger']]], $container);
        $container->register('doctrine', stdClass::class);
        self::assertTrue($container->hasDefinition(RecordShortLinkClickHandler::class));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no Messenger bus');
        (new ShortLinkCompilerPass())->process($container);
    }

    public function testPrependRegistersTheMappingOnlyForOrmHosts(): void
    {
        $extension = new NowoShortLinkExtension();

        $none = new ContainerBuilder();
        $extension->prepend($none);
        self::assertSame([], $none->getExtensionConfig('doctrine'));

        $dbalOnly = new ContainerBuilder();
        $dbalOnly->registerExtension(new FakeDoctrineExtension());
        $dbalOnly->loadFromExtension('doctrine', ['dbal' => []]);
        $extension->prepend($dbalOnly);
        self::assertCount(1, $dbalOnly->getExtensionConfig('doctrine'));

        $orm = new ContainerBuilder();
        $orm->registerExtension(new FakeDoctrineExtension());
        $orm->registerExtension($extension);
        $orm->loadFromExtension('doctrine', ['orm' => []]);
        $orm->loadFromExtension('nowo_short_link', ['doctrine' => ['entity_manager' => 'links', 'mapping' => 'attribute']]);
        $orm->loadFromExtension('nowo_short_link', ['public_base_url' => '%env(X)%']);
        $extension->prepend($orm);
        self::assertSame('attribute', $this->at($orm->getExtensionConfig('doctrine')[0], 'orm', 'entity_managers', 'links', 'mappings', 'NowoShortLinkBundle', 'type'));

        $xml = new ContainerBuilder();
        $xml->registerExtension(new FakeDoctrineExtension());
        $xml->loadFromExtension('doctrine', ['orm' => []]);
        $extension->prepend($xml);
        self::assertSame('xml', $this->at($xml->getExtensionConfig('doctrine')[0], 'orm', 'entity_managers', 'default', 'mappings', 'NowoShortLinkBundle', 'type'));
    }

    private function bridge(ContainerBuilder $container): Definition
    {
        $guards = $container->getDefinition(ChainTargetGuard::class)->getArgument(0);
        self::assertIsArray($guards);
        $bridge = $guards[1];
        self::assertInstanceOf(Definition::class, $bridge);

        return $bridge;
    }

    private function at(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }
}
