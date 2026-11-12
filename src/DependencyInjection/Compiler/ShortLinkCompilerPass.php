<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\ShortLinkBundle\DependencyInjection\NowoShortLinkExtension;
use Nowo\ShortLinkBundle\Domain\Model\ClickWriteMode;
use Nowo\ShortLinkBundle\Domain\Port\TenantLockInterface;
use Nowo\ShortLinkBundle\Infrastructure\Lock\NoTenantLock;
use Nowo\ShortLinkBundle\Infrastructure\Lock\SymfonyTenantLock;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Lock\LockFactory;

use function class_exists;
use function sprintf;

/**
 * Checks the Messenger click mode (a bus exists), the UI dependencies (UiKit, FormKit, forms, translator, Twig) when
 * `ui.enabled`, checks DoctrineBundle (the links are stored through Doctrine DBAL) and switches the tenant lock to symfony/lock
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
        if ($container->getParameter(NowoShortLinkExtension::ALIAS . '.click_write_mode') === ClickWriteMode::Messenger->value && !$container->has('messenger.default_bus')) {
            throw new LogicException('nowo_short_link.click_log.write is "messenger" but no Messenger bus exists: install symfony/messenger and configure framework.messenger.');
        }
        if ($container->getParameter(NowoShortLinkExtension::ALIAS . '.ui_enabled') === true) {
            foreach (['nowo_ui_kit' => 'nowo-tech/ui-kit-bundle (NowoUiKitBundle)', 'nowo_form_kit' => 'nowo-tech/form-kit-bundle (NowoFormKitBundle)'] as $extension => $package) {
                if (!$container->hasExtension($extension)) {
                    throw new LogicException(sprintf('nowo_short_link.ui.enabled needs %s: register the bundle in config/bundles.php.', $package));
                }
            }
            foreach (['form.factory' => 'framework.form', 'translator' => 'framework.translator', 'twig' => 'TwigBundle'] as $service => $feature) {
                if (!$container->has($service)) {
                    throw new LogicException(sprintf('nowo_short_link.ui.enabled needs %s (service "%s").', $feature, $service));
                }
            }
        }
        if ((string) $container->getAlias(TenantLockInterface::class) !== NoTenantLock::class || !$container->has('lock.factory') || !class_exists(LockFactory::class)) {
            return;
        }
        $container->register(SymfonyTenantLock::class, SymfonyTenantLock::class)->setArguments([new Reference('lock.factory')]);
        $container->setAlias(TenantLockInterface::class, SymfonyTenantLock::class)->setPublic(true);
    }
}
