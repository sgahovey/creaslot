<?php

declare(strict_types=1);

namespace App\Controller\Auditeur;

use App\Entity\Reservation;
use App\Enum\StatutReservation;
use App\Exception\ReservationNonAnnulableException;
use App\Form\AnnulationReservationType;
use App\Security\ReservationVoter;
use App\Service\ReservationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_AUDITEUR')]
final class ReservationAnnulationController extends AbstractController
{
    public function __construct(
        private readonly ReservationService $reservationService,
    ) {
    }

    #[Route('/reservation/{id}/annuler', name: 'app_reservation_annulation', methods: ['POST'])]
    public function annuler(Reservation $reservation, Request $request): Response
    {
        $this->denyAccessUnlessGranted(ReservationVoter::CANCEL, $reservation);

        $form = $this->createForm(AnnulationReservationType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'Demande d\'annulation invalide.');

            return $this->redirigerVersListe($request);
        }

        try {
            $this->reservationService->annuler(
                $reservation,
                $form->get('motifAnnulation')->getData(),
            );
            $this->addFlash('success', 'Votre réservation a été annulée. Un email de confirmation vous est envoyé.');
        } catch (ReservationNonAnnulableException $e) {
            $this->addFlash('error', $this->messageRefus($e->getStatut()));
        }

        return $this->redirigerVersListe($request);
    }

    private function messageRefus(StatutReservation $statut): string
    {
        return $statut === StatutReservation::ANNULEE
            ? 'Cette réservation a déjà été annulée.'
            : 'Cette réservation est passée, vous ne pouvez plus l\'annuler.';
    }

    /**
     * Preserve le filtre actif si le Referer pointe vers la liste des reservations,
     * sinon redirige vers la route nue. Validation contre l'open-redirect :
     * on n'utilise que le path extrait (jamais le host) et on verifie qu'il
     * commence par la base de la route /mes-reservations.
     */
    private function redirigerVersListe(Request $request): Response
    {
        $referer = $request->headers->get('referer', '');
        if ($referer !== '') {
            $path = parse_url($referer, PHP_URL_PATH);
            $baseListe = $this->generateUrl('app_mes_reservations');
            if (is_string($path) && str_starts_with($path, $baseListe)) {
                return $this->redirect($referer);
            }
        }

        return $this->redirectToRoute('app_mes_reservations');
    }
}
