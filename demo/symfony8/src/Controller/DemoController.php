<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * S0 demo home: shows that the bundle is installed, its published parameters, the MySQL connection and the
 * FrankenPHP mode.
 */
final class DemoController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%nowo_short_link.redirect_path_prefix%')] private readonly string $redirectPrefix,
        #[Autowire('%nowo_short_link.ui_path_prefix%')] private readonly string $uiPrefix,
        #[Autowire('%nowo_short_link.ui_enabled%')] private readonly bool $uiEnabled,
    ) {
    }

    #[Route('/', name: 'demo_home', methods: ['GET'])]
    public function home(Request $request): Response
    {
        try {
            $database = (string) $this->connection->fetchOne('SELECT VERSION()');
        } catch (Throwable) {
            $database = null;
        }

        return $this->render('demo/home.html.twig', [
            'redirect_prefix' => $this->redirectPrefix,
            'ui_prefix'       => $this->uiPrefix,
            'ui_enabled'      => $this->uiEnabled,
            'database'        => $database,
            'worker'          => $request->server->has('FRANKENPHP_WORKER'),
        ]);
    }
}
