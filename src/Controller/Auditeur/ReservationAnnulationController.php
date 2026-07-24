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

/**
 * Annulation d'une réservation par l'Auditeur propriétaire (US-3.3).
 *
 * Accès réservé à ROLE_AUDITEUR (IsGranted de classe).
 */
#[IsGranted('ROLE_AUDITEUR')]
final class ReservationAnnulationController extends AbstractController
{
    public function __construct(
        private readonly ReservationService $reservationService,
    ) {
    }

    /**
     * Annule une réservation à la demande de son Auditeur propriétaire.
     *
     * Le Voter CANCEL réserve l'action au propriétaire : le Personnel n'annule jamais
     * la réservation d'un Auditeur (il désactive son créneau, action distincte). Une
     * réservation déjà annulée, ou dont le créneau est passé, n'est plus annulable
     * (ReservationNonAnnulableException) et le motif exact est restitué. Le succès
     * déclenche un e-mail de confirmation.
     */
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
     * sinon redirige vers la route nue. Protection contre l'open-redirect : la
     * redirection ne reutilise QUE le chemin (et sa chaine de requete) extrait du
     * Referer, jamais son hote, et seulement si ce chemin commence par la base de
     * la route /mes-reservations. Un Referer pointant vers un domaine tiers est
     * ainsi ramene sur le chemin local, sans jamais renvoyer vers l'exterieur.
     */
    private function redirigerVersListe(Request $request): Response
    {
        $referer = $request->headers->get('referer', '');
        $composants = $referer !== '' ? parse_url($referer) : false;

        if (is_array($composants)) {
            $chemin = $composants['path'] ?? '';
            $baseListe = $this->generateUrl('app_mes_reservations');
            if (str_starts_with($chemin, $baseListe)) {
                if (isset($composants['query'])) {
                    $chemin .= '?' . $composants['query'];
                }

                return $this->redirect($chemin);
            }
        }

        return $this->redirectToRoute('app_mes_reservations');
    }
}
