<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\DependencyInjection\Compiler;

use Nowo\ShortLinkBundle\DependencyInjection\NowoShortLinkExtension;
use Nowo\ShortLinkBundle\Infrastructure\Http\ShortLinkConflictListener;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\AfterCommitMiddleware;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function is_string;
use function preg_match;
use function sprintf;

/**
 * REQ-RUNTIME-003 (audit UE-CF-1): the bundle's DBAL middlewares wrap only the connection of the bundle's entity
 * manager (`doctrine.entity_manager`, else the default one), never the host's other connections.
 *
 * - {@see AfterCommitMiddleware} (after-commit events of writes that joined a host transaction): always scoped.
 * - Commit tracking of the conflict answer: when nowo-tech/transaction-doctrine's bundle is registered with
 *   `commit_detection` (its {@see CommitLedger} service exists), its own middleware already counts every commit, so the
 *   bundle's middleware and ledger are removed and the conflict listener reads transaction-doctrine's ledger: no
 *   connection is wrapped twice. Otherwise the bundle's middleware is scoped like the after-commit one.
 *   `conflict_response.commit_tracking: off`: the extension registered neither.
 *
 * Runs before DoctrineBundle's MiddlewaresPass (priority 0).
 */
final class ShortLinkCommitTrackingPass implements CompilerPassInterface
{
    /**
     * Scopes the middlewares, or swaps the ledger for transaction-doctrine's.
     */
    public function process(ContainerBuilder $container): void
    {
        $connection = null;
        if ($container->hasDefinition(AfterCommitMiddleware::class)) {
            $connection = $this->bundleConnection($container);
            $container->getDefinition(AfterCommitMiddleware::class)
                ->clearTag('doctrine.middleware')
                ->addTag('doctrine.middleware', ['connection' => $connection]);
        }

        if (!$container->hasDefinition(NowoShortLinkExtension::COMMIT_TRACKING_MIDDLEWARE)) {
            return;
        }

        if ($container->has(CommitLedger::class)) {
            $container->removeDefinition(NowoShortLinkExtension::COMMIT_TRACKING_MIDDLEWARE);
            $container->removeDefinition(NowoShortLinkExtension::COMMIT_LEDGER_SERVICE);
            if ($container->hasDefinition(ShortLinkConflictListener::class)) {
                $container->getDefinition(ShortLinkConflictListener::class)->replaceArgument(1, new Reference(CommitLedger::class));
            }

            return;
        }

        $container->getDefinition(NowoShortLinkExtension::COMMIT_TRACKING_MIDDLEWARE)
            ->clearTag('doctrine.middleware')
            ->addTag('doctrine.middleware', ['connection' => $connection ?? $this->bundleConnection($container)]);
    }

    /**
     * The connection of the bundle's entity manager; the default connection when it cannot be resolved.
     */
    private function bundleConnection(ContainerBuilder $container): string
    {
        $manager = $container->hasParameter(NowoShortLinkExtension::ENTITY_MANAGER_PARAMETER) ? $container->getParameter(NowoShortLinkExtension::ENTITY_MANAGER_PARAMETER) : null;
        if (!is_string($manager) || $manager === '') {
            $manager = $container->hasParameter('doctrine.default_entity_manager') ? $container->getParameter('doctrine.default_entity_manager') : 'default';
        }

        $id       = sprintf('doctrine.orm.%s_entity_manager', is_string($manager) ? $manager : 'default');
        $argument = $container->hasDefinition($id) ? ($container->getDefinition($id)->getArguments()[0] ?? null) : null;
        if ($argument instanceof Reference && preg_match('/^doctrine\.dbal\.(.+)_connection$/D', (string) $argument, $match) === 1) {
            return $match[1];
        }

        $default = $container->hasParameter('doctrine.default_connection') ? $container->getParameter('doctrine.default_connection') : null;

        return is_string($default) ? $default : 'default';
    }
}
