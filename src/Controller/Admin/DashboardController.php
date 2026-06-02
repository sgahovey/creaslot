<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\DashboardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tableau de bord du Super-admin (US-5.1).
 *
 * Protégé au niveau classe par `ROLE_SUPER_ADMIN` (défense en profondeur : la
 * règle `access_control ^/admin` de security.yaml constitue la 2e barrière).
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {}

    #[Route('/admin', name: 'app_admin_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/dashboard/index.html.twig', [
            'kpis'       => $this->dashboardService->calculerKpis(),
            'occupation' => $this->dashboardService->getOccupationParJour(),
        ]);
    }
}
