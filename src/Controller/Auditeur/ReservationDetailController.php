<?php

declare(strict_types=1);

namespace App\Controller\Auditeur;

use App\Entity\Reservation;
use App\Security\ReservationVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_AUDITEUR')]
final class ReservationDetailController extends AbstractController
{
    #[Route('/reservation/{id}', name: 'app_reservation_detail', methods: ['GET'])]
    public function detail(Reservation $reservation): Response
    {
        $this->denyAccessUnlessGranted(ReservationVoter::VIEW, $reservation);

        return $this->render('auditeur/reservation/detail.html.twig', [
            'reservation' => $reservation,
        ]);
    }
}
