<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\ShortLinkBundle\Domain\Port\TenantLockInterface;
use Nowo\ShortLinkBundle\Infrastructure\Lock\NoTenantLock;
use Nowo\ShortLinkBundle\Infrastructure\Lock\SymfonyTenantLock;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Lock\LockFactory;

use function class_exists;

/**
 * Checks DoctrineBundle (the links are stored through Doctrine DBAL) and switches the tenant lock to symfony/lock
 * when the host has a lock factory (`lock.factory`), so plan limits stay exact under concurrent creates.
 */
final class ShortLinkCompilerPass implements CompilerPassInterface
{
    /**
     * Runs the checks.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has('doctrine')) {
            throw new LogicException('nowo_short_link needs DoctrineBundle with the ORM: install and enable doctrine/doctrine-bundle (the links are stored through Doctrine DBAL).');
        }
        if ((string) $container->getAlias(TenantLockInterface::class) !== NoTenantLock::class || !$container->has('lock.factory') || !class_exists(LockFactory::class)) {
            return;
        }
        $container->register(SymfonyTenantLock::class, SymfonyTenantLock::class)->setArguments([new Reference('lock.factory')]);
        $container->setAlias(TenantLockInterface::class, SymfonyTenantLock::class)->setPublic(true);
    }
}
