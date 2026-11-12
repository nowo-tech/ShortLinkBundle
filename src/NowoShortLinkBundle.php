<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle;

use Nowo\ShortLinkBundle\DependencyInjection\Compiler\ShortLinkCompilerPass;
use Nowo\ShortLinkBundle\DependencyInjection\NowoShortLinkExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Short links for Symfony: per-tenant short codes behind a public stateless redirect, a target guard, a
 * privacy-preserving click log with daily rollups, a PHP API for generated links and a UiKit + FormKit UI.
 *
 * S1: the manager, the DBAL store, the ports with their defaults, the target guard and the Doctrine mapping. The
 * redirect, statistics and UI follow with the next slices (see docs/ROADMAP.md).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoShortLinkBundle extends Bundle
{
    /** Translation domain of the bundle UI copy (REQ-I18N-003) and Twig namespace (REQ-TWIG-002). */
    public const TRANSLATION_DOMAIN = 'NowoShortLinkBundle';

    /**
     * Adds the compiler pass (DoctrineBundle check, symfony/lock tenant lock).
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new ShortLinkCompilerPass());
    }

    /**
     * The extension alias is `nowo_short_link` (not the default derived from the class name).
     */
    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            // @igor-ignore - Boot-time Symfony Bundle extension cache (not request state).
            $this->extension = new NowoShortLinkExtension();
        }

        return $this->extension;
    }
}
