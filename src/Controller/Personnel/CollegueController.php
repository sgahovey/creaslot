<?php

declare(strict_types=1);

namespace App\Controller\Personnel;

use App\Entity\Utilisateur;
use App\Service\CollegueService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Annuaire des collègues du Personnel (US-2.x).
 *
 * Accès réservé à ROLE_PERSONNEL (IsGranted de classe).
 */
#[IsGranted('ROLE_PERSONNEL')]
class CollegueController extends AbstractController
{
    public function __construct(
        private readonly CollegueService $collegueService,
    ) {
    }

    /**
     * Liste des collègues, filtrable sur la disponibilité et sur le seul service du
     * membre connecté.
     *
     * Le filtre par service est volontairement restreint au service de l'utilisateur :
     * un paramètre `service=X` arbitraire est ignoré, pour empêcher d'énumérer les
     * autres services par l'URL.
     */
    #[Route('/collegues', name: 'app_collegues_liste', methods: ['GET'])]
    public function liste(Request $request): Response
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();
        $monServiceId = $utilisateur->getService()?->getId();
        $disponibles = $request->query->getString('disponibles', '0') === '1';
        $filtreMonService = $request->query->getString('mon-service', '0') === '1';

        // Le seul filtre par service autorisé est le propre service de l'utilisateur.
        // Tout paramètre service=X arbitraire est ignoré.
        $serviceId = ($filtreMonService && $monServiceId !== null) ? $monServiceId : null;

        $collegues = $this->collegueService->getCollegues(
            $utilisateur,
            $serviceId,
            $disponibles,
        );

        return $this->render('personnel/collegue/liste.html.twig', [
            'collegues'        => $collegues,
            'filtreMonService' => $filtreMonService && $monServiceId !== null,
            'disponibles'      => $disponibles,
            'aUnService'       => $monServiceId !== null,
        ]);
    }
}
