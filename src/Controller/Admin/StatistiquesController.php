<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\StatistiquesService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Statistiques d'occupation par service et par type de RDV du Super-admin (US-5.8).
 *
 * Protégé au niveau classe par `ROLE_SUPER_ADMIN` (défense en profondeur : la
 * règle `access_control ^/admin` de security.yaml constitue la 2e barrière). Le
 * rendu est entièrement serveur : les séries des graphiques sont passées en
 * data-attributes au contrôleur Stimulus, sans endpoint JSON.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class StatistiquesController extends AbstractController
{
    public function __construct(
        private readonly StatistiquesService $statistiquesService,
    ) {}

    #[Route('/admin/statistiques', name: 'app_admin_statistiques', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/statistiques/index.html.twig', [
            'statistiques' => $this->statistiquesService->calculerStatistiques(),
        ]);
    }
}
