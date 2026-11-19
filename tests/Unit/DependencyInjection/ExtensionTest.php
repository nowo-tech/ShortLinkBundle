<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\ShortLinkBundle\Application\CodeGenerator;
use Nowo\ShortLinkBundle\Application\Guard\ChainTargetGuard;
use Nowo\ShortLinkBundle\Application\Guard\OwnHostGuard;
use Nowo\ShortLinkBundle\DependencyInjection\Compiler\ShortLinkCompilerPass;
use Nowo\ShortLinkBundle\DependencyInjection\NowoShortLinkExtension;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkAccessInterface;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkQuotaInterface;
use Nowo\ShortLinkBundle\Domain\Port\TargetDenylistInterface;
use Nowo\ShortLinkBundle\Domain\Port\TargetGuardInterface;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkManagerInterface;
use Nowo\ShortLinkBundle\Infrastructure\Doctrine\ClickTablesSchemaListener;
use Nowo\ShortLinkBundle\Infrastructure\Http\RedirectController;
use Nowo\ShortLinkBundle\Infrastructure\Http\ShortLinkConflictListener;
use Nowo\ShortLinkBundle\Infrastructure\Messenger\RecordShortLinkClickHandler;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\DbalShortLinkStore;
use Nowo\ShortLinkBundle\Infrastructure\Routing\RedirectRouteLoader;
use Nowo\ShortLinkBundle\Presentation\Controller\ShortLinkUiController;
use Nowo\ShortLinkBundle\Presentation\Routing\UiRouteLoader;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

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

    public function testTheConflictAnswerFollowsTransactionDoctrineOrTheBundleOptions(): void
    {
        $container = new ContainerBuilder();
        (new NowoShortLinkExtension())->load([], $container);
        $listener = $container->getDefinition(ShortLinkConflictListener::class);
        $policy   = $listener->getArgument(0);
        self::assertInstanceOf(Reference::class, $policy, 'unset: transaction-doctrine\'s policy service when present');
        self::assertSame(ConflictResponsePolicy::class, (string) $policy);
        $ledger = $listener->getArgument(1);
        self::assertInstanceOf(Reference::class, $ledger);
        self::assertSame(NowoShortLinkExtension::COMMIT_LEDGER_SERVICE, (string) $ledger);
        self::assertTrue($container->hasDefinition(NowoShortLinkExtension::COMMIT_TRACKING_MIDDLEWARE));
        self::assertNull($container->getParameter(NowoShortLinkExtension::ENTITY_MANAGER_PARAMETER));
        self::assertSame(10, $container->getDefinition(DbalShortLinkStore::class)->getArgument(5), 'quota.lock_timeout');

        $container = new ContainerBuilder();
        (new NowoShortLinkExtension())->load([['conflict_response' => ['status' => 503, 'commit_tracking' => 'off'], 'quota' => ['lock_timeout' => 3], 'doctrine' => ['entity_manager' => 'links']]], $container);
        $listener = $container->getDefinition(ShortLinkConflictListener::class);
        $policy   = $listener->getArgument(0);
        self::assertInstanceOf(Definition::class, $policy);
        self::assertSame([503, ConflictResponsePolicy::DEFAULT_RETRY_AFTER], $policy->getArguments());
        self::assertNull($listener->getArgument(1), 'commit tracking off: no ledger');
        self::assertFalse($container->hasDefinition(NowoShortLinkExtension::COMMIT_TRACKING_MIDDLEWARE));
        self::assertFalse($container->hasDefinition(NowoShortLinkExtension::COMMIT_LEDGER_SERVICE));
        self::assertSame('links', $container->getParameter(NowoShortLinkExtension::ENTITY_MANAGER_PARAMETER));
        self::assertSame(3, $container->getDefinition(DbalShortLinkStore::class)->getArgument(5));

        $container = new ContainerBuilder();
        (new NowoShortLinkExtension())->load([['conflict_response' => ['retry_after' => 4]]], $container);
        $policy = $container->getDefinition(ShortLinkConflictListener::class)->getArgument(0);
        self::assertInstanceOf(Definition::class, $policy);
        self::assertSame([ConflictResponsePolicy::STATUS_CONFLICT, 4], $policy->getArguments());
    }

    /**
     * `target.allow_own_host`: the own-host guard wraps the chain (or the host's guard) with configuration only.
     */
    public function testAllowOwnHostWrapsTheChainOrTheHostGuard(): void
    {
        $container = new ContainerBuilder();
        (new NowoShortLinkExtension())->load([[
            'public_base_url' => 'https://links.example',
            'redirect'        => ['path_prefix' => '/l'],
            'target'          => ['allow_own_host' => true, 'own_hosts' => ['beacon.example']],
        ]], $container);

        self::assertSame(OwnHostGuard::class, (string) $container->getAlias(TargetGuardInterface::class));
        self::assertTrue($container->getAlias(TargetGuardInterface::class)->isPublic());
        $own = $container->getDefinition(OwnHostGuard::class);
        self::assertEquals(new Reference(ChainTargetGuard::class), $own->getArgument(0));
        self::assertSame(['beacon.example'], $own->getArgument(1));
        self::assertSame(['/s/', '/l/'], $own->getArgument(2));
        self::assertSame('https://links.example', $own->getArgument(3));

        $custom = new ContainerBuilder();
        (new NowoShortLinkExtension())->load([['target' => ['allow_own_host' => true, 'guard' => 'app.guard']]], $custom);
        self::assertSame(OwnHostGuard::class, (string) $custom->getAlias(TargetGuardInterface::class));
        self::assertEquals(new Reference('app.guard'), $custom->getDefinition(OwnHostGuard::class)->getArgument(0));
        self::assertFalse($custom->hasDefinition(ChainTargetGuard::class));

        $off = new ContainerBuilder();
        (new NowoShortLinkExtension())->load([['target' => ['guard' => 'app.guard']]], $off);
        self::assertSame('app.guard', (string) $off->getAlias(TargetGuardInterface::class));
        self::assertFalse($off->hasDefinition(OwnHostGuard::class), 'off by default');
    }

    /**
     * REQ-SF-004: both route loaders are tagged routing.loader, and the controllers (they carry #[Route]) are not
     * autoconfigured (rule 7) but tagged controller.service_arguments explicitly.
     */
    public function testRouteLoadersAreRegisteredAndTheControllersAreNotAutoconfigured(): void
    {
        $container = new ContainerBuilder();
        (new NowoShortLinkExtension())->load([['ui' => ['enabled' => true]]], $container);

        foreach ([RedirectRouteLoader::class, UiRouteLoader::class] as $loader) {
            self::assertTrue($container->getDefinition($loader)->hasTag('routing.loader'), $loader);
            self::assertContains('%kernel.environment%', $container->getDefinition($loader)->getArguments(), $loader);
        }
        foreach ([RedirectController::class, ShortLinkUiController::class] as $controller) {
            $definition = $container->getDefinition($controller);
            self::assertFalse($definition->isAutoconfigured(), $controller);
            self::assertTrue($definition->hasTag('controller.service_arguments'), $controller);
        }
    }

    public function testATenantContextNeedsATenantAwareAccessPort(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('access.provider');
        (new NowoShortLinkExtension())->load([['tenancy' => ['context' => 'app.tenant']]], new ContainerBuilder());
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
