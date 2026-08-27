<?php

declare(strict_types=1);

namespace App\Controller\Auditeur;

use App\Entity\Reservation;
use App\Security\ReservationVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Consultation du détail d'une réservation par l'Auditeur (US-3.2).
 *
 * Accès réservé à ROLE_AUDITEUR (IsGranted de classe).
 */
#[IsGranted('ROLE_AUDITEUR')]
final class ReservationDetailController extends AbstractController
{
    /**
     * Affiche le détail d'une réservation.
     *
     * Lecture seule ; le Voter VIEW restreint l'accès au propriétaire, empêchant la
     * consultation de la réservation d'autrui par simple manipulation de l'URL.
     */
    #[Route('/reservation/{id}', name: 'app_reservation_detail', methods: ['GET'])]
    public function detail(Reservation $reservation): Response
    {
        $this->denyAccessUnlessGranted(ReservationVoter::VIEW, $reservation);

        return $this->render('auditeur/reservation/detail.html.twig', [
            'reservation' => $reservation,
        ]);
    }
}
