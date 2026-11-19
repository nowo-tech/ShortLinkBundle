<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit\DependencyInjection\Compiler;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\MiddlewaresPass;
use Nowo\ShortLinkBundle\DependencyInjection\Compiler\ShortLinkCommitTrackingPass;
use Nowo\ShortLinkBundle\DependencyInjection\NowoShortLinkExtension;
use Nowo\ShortLinkBundle\Infrastructure\Http\ShortLinkConflictListener;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\AfterCommitMiddleware;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Audit UE-CF-1: the bundle's DBAL middlewares (after-commit queue, commit tracking) never wrap the host's other
 * connections, and the commit tracking is not registered at all when transaction-doctrine's commit_detection already
 * counts commits.
 */
#[CoversClass(ShortLinkCommitTrackingPass::class)]
final class ShortLinkCommitTrackingPassTest extends TestCase
{
    private const MIDDLEWARE = NowoShortLinkExtension::COMMIT_TRACKING_MIDDLEWARE;

    private const AFTER_COMMIT = AfterCommitMiddleware::class;

    public function testOnlyTheConnectionOfTheBundleEntityManagerIsWrapped(): void
    {
        $container = $this->container('links');

        $this->compile($container);

        foreach ([self::MIDDLEWARE, self::AFTER_COMMIT] as $middleware) {
            self::assertTrue($container->hasDefinition($middleware . '.links'), $middleware . ': the bundle connection');
            self::assertFalse($container->hasDefinition($middleware . '.default'), $middleware . ': never the host connection');
        }
        self::assertSame(NowoShortLinkExtension::COMMIT_LEDGER_SERVICE, $this->ledgerOf($container));
    }

    public function testWithoutDoctrineEntityManagerTheDefaultManagerConnectionIsWrapped(): void
    {
        $container = $this->container(null);
        $container->setParameter('doctrine.default_entity_manager', 'links');

        $this->compile($container);

        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.links'));
        self::assertTrue($container->hasDefinition(self::AFTER_COMMIT . '.links'));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.default'));
    }

    public function testAnUnresolvableManagerFallsBackToTheDefaultConnection(): void
    {
        $container = $this->container('missing');
        $container->setParameter('doctrine.default_connection', 'links');
        $this->compile($container);
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.links'));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.default'));

        $container = $this->container(null);
        $container->getParameterBag()->remove(NowoShortLinkExtension::ENTITY_MANAGER_PARAMETER);
        $this->compile($container);
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.default'), 'no parameter, no default manager or connection: "default"');
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.links'));
    }

    public function testTransactionDoctrineCommitDetectionIsReusedAndNoConnectionIsWrappedTwice(): void
    {
        $container = $this->container('links');
        $container->setDefinition('nowo_transaction_doctrine.commit_ledger', new Definition(CommitLedger::class));
        $container->setAlias(CommitLedger::class, 'nowo_transaction_doctrine.commit_ledger');

        $this->compile($container);

        self::assertFalse($container->hasDefinition(self::MIDDLEWARE));
        self::assertFalse($container->hasDefinition(NowoShortLinkExtension::COMMIT_LEDGER_SERVICE));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.links'));
        self::assertTrue($container->hasDefinition(self::AFTER_COMMIT . '.links'), 'the after-commit queue stays, scoped');
        self::assertFalse($container->hasDefinition(self::AFTER_COMMIT . '.default'));
        self::assertSame(CommitLedger::class, $this->ledgerOf($container));
    }

    public function testTransactionDoctrineLedgerWithoutTheListenerOnlyDropsTheMiddleware(): void
    {
        $container = $this->container('links');
        $container->removeDefinition(ShortLinkConflictListener::class);
        $container->setDefinition(CommitLedger::class, new Definition(CommitLedger::class));

        (new ShortLinkCommitTrackingPass())->process($container);

        self::assertFalse($container->hasDefinition(self::MIDDLEWARE));
    }

    public function testCommitTrackingOffStillScopesTheAfterCommitMiddleware(): void
    {
        $container = $this->container('links');
        $container->removeDefinition(self::MIDDLEWARE);
        $container->removeDefinition(NowoShortLinkExtension::COMMIT_LEDGER_SERVICE);

        $this->compile($container);

        self::assertTrue($container->hasDefinition(self::AFTER_COMMIT . '.links'));
        self::assertFalse($container->hasDefinition(self::AFTER_COMMIT . '.default'));
    }

    public function testAnEmptyContainerIsLeftAlone(): void
    {
        $container = new ContainerBuilder();

        (new ShortLinkCommitTrackingPass())->process($container);

        self::assertFalse($container->hasDefinition(self::MIDDLEWARE));
        self::assertFalse($container->hasDefinition(self::AFTER_COMMIT));
    }

    private function ledgerOf(ContainerBuilder $container): string
    {
        $ledger = $container->getDefinition(ShortLinkConflictListener::class)->getArgument(1);
        self::assertInstanceOf(Reference::class, $ledger);

        return (string) $ledger;
    }

    private function container(?string $entityManager): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoShortLinkExtension::ENTITY_MANAGER_PARAMETER, $entityManager);
        $container->setParameter('doctrine.connections', ['default' => 'doctrine.dbal.default_connection', 'links' => 'doctrine.dbal.links_connection']);
        $container->setDefinition('doctrine.dbal.default_connection.configuration', new Definition());
        $container->setDefinition('doctrine.dbal.links_connection.configuration', new Definition());
        $container->setDefinition('doctrine.orm.links_entity_manager', new Definition(null, [new Reference('doctrine.dbal.links_connection')]));
        $container->setDefinition(AfterCommitQueue::class, new Definition(AfterCommitQueue::class));
        $container->setDefinition(self::AFTER_COMMIT, (new Definition(AfterCommitMiddleware::class, [new Reference(AfterCommitQueue::class)]))
            ->addTag('doctrine.middleware'));
        $container->setDefinition(NowoShortLinkExtension::COMMIT_LEDGER_SERVICE, new Definition(CommitLedger::class));
        $container->setDefinition(self::MIDDLEWARE, (new Definition(CommitTrackingMiddleware::class, [new Reference(NowoShortLinkExtension::COMMIT_LEDGER_SERVICE)]))
            ->addTag('doctrine.middleware'));
        $container->setDefinition(ShortLinkConflictListener::class, new Definition(ShortLinkConflictListener::class, [null, new Reference(NowoShortLinkExtension::COMMIT_LEDGER_SERVICE), null]));

        return $container;
    }

    private function compile(ContainerBuilder $container): void
    {
        (new ShortLinkCommitTrackingPass())->process($container);
        (new MiddlewaresPass())->process($container);
    }
}
