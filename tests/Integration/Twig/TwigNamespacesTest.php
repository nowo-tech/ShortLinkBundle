<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration\Twig;

use Nowo\ShortLinkBundle\Tests\Integration\Kernel\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Environment;

use function file_put_contents;
use function sys_get_temp_dir;
use function uniqid;

/**
 * REQ-TWIG-002 on a real kernel with TwigBundle: the canonical `@NowoShortLinkBundle` namespace, the legacy
 * `@NowoShortLink` one and the host override directory `templates/bundles/NowoShortLinkBundle/`.
 */
final class TwigNamespacesTest extends KernelTestCase
{
    private string $projectDir = '';

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->projectDir !== '') {
            (new Filesystem())->remove($this->projectDir);
        }
    }

    public function testBothNamespacesResolveTheBundleTemplates(): void
    {
        $twig = $this->twig(false);

        foreach (['ui/list.html.twig', 'ui/form.html.twig', 'ui/stats.html.twig', 'redirect/not_found.html.twig'] as $name) {
            self::assertTrue($twig->getLoader()->exists('@NowoShortLinkBundle/' . $name), $name);
            self::assertTrue($twig->getLoader()->exists('@NowoShortLink/' . $name), $name);
            self::assertTrue($twig->getLoader()->exists('@!NowoShortLinkBundle/' . $name), $name);
        }
    }

    public function testHostOverrideDirectoryWinsInBothNamespaces(): void
    {
        $twig = $this->twig(true);

        self::assertSame('HOST OVERRIDE', $twig->render('@NowoShortLinkBundle/redirect/not_found.html.twig'));
        self::assertSame('HOST OVERRIDE', $twig->render('@NowoShortLink/redirect/not_found.html.twig'));
        self::assertStringNotContainsString('HOST OVERRIDE', $twig->getLoader()->getSourceContext('@!NowoShortLinkBundle/redirect/not_found.html.twig')->getCode());
    }

    private function twig(bool $override): Environment
    {
        $this->projectDir = sys_get_temp_dir() . '/nowo-short-link-project-' . uniqid('', true);
        $dir              = $this->projectDir . '/templates/bundles/NowoShortLinkBundle/redirect';
        (new Filesystem())->mkdir($dir);
        if ($override) {
            file_put_contents($dir . '/not_found.html.twig', 'HOST OVERRIDE');
        }
        $container = $this->bootKernel([], null, null, false, false, $this->projectDir)->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $twig = $container->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig;
    }
}
