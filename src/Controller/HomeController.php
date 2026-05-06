<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('home/index.html.twig', [
            'symfony_version'   => \Symfony\Component\HttpKernel\Kernel::VERSION,
            'php_version'       => PHP_VERSION,
            'extensions_status' => $this->collectExtensionsStatus(),
        ]);
    }

    /**
     * Collecte le statut des extensions PHP requises par Symfony.
     *
     * @return array<string, bool>
     */
    private function collectExtensionsStatus(): array
    {
        return [
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'intl'      => extension_loaded('intl'),
            'zip'       => extension_loaded('zip'),
            'opcache'   => function_exists('opcache_get_status') && opcache_get_status(false) !== false,
        ];
    }
}
