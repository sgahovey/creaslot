<?php

declare(strict_types=1);

namespace App\Controller\Auditeur;

use App\Entity\Utilisateur;
use App\Repository\ReservationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_AUDITEUR')]
class ReservationListeController extends AbstractController
{
    private const FILTRES_VALIDES = ['toutes', 'a_venir', 'passees', 'annulees'];

    public function __construct(
        private readonly ReservationRepository $reservationRepository,
    ) {}

    #[Route('/mes-reservations', name: 'app_mes_reservations', methods: ['GET'])]
    public function liste(Request $request): Response
    {
        $filtre = $request->query->getString('filtre', 'toutes');
        if (!in_array($filtre, self::FILTRES_VALIDES, true)) {
            $filtre = 'toutes';
        }

        $page = max(1, $request->query->getInt('page', 1));

        /** @var Utilisateur $auditeur */
        $auditeur = $this->getUser();

        $paginator = $this->reservationRepository->findByAuditeurWithFilters($auditeur, $filtre, $page);
        $total     = count($paginator);

        return $this->render('auditeur/reservation/liste.html.twig', [
            'reservations' => $paginator,
            'total'        => $total,
            'page'         => $page,
            'nbPages'      => max(1, (int) ceil($total / 12)),
            'filtreActif'  => $filtre,
        ]);
    }
}
