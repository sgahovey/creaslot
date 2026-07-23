<?php

declare(strict_types=1);

namespace App\Controller\Auditeur;

use App\Entity\Creneau;
use App\Entity\Utilisateur;
use App\Enum\MotifRefusReservation;
use App\Exception\CreneauIndisponibleException;
use App\Form\ReservationType;
use App\Service\ReservationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Réservation d'un créneau par l'Auditeur (US-3.1).
 *
 * Accès réservé à ROLE_AUDITEUR (IsGranted de classe).
 */
#[IsGranted('ROLE_AUDITEUR')]
class ReservationController extends AbstractController
{
    public function __construct(
        private readonly ReservationService $reservationService,
    ) {
    }

    /**
     * Affiche le formulaire de réservation puis enregistre la réservation.
     *
     * Trois filtres métier se succèdent, du moins au plus coûteux :
     *  1. un refus préalable court-circuite l'affichage si le créneau est inactif,
     *     passé, déjà réservé, tenu par un propriétaire inactif, ou appartient à
     *     l'Auditeur lui-même (on ne réserve pas son propre créneau) ;
     *  2. à la soumission, un chevauchement avec une autre réservation active de
     *     l'Auditeur au même horaire est bloqué (on ne peut pas être à deux RDV en
     *     même temps) ;
     *  3. la réservation peut encore échouer si le créneau a été pris entre l'affichage
     *     et la validation : CreneauIndisponibleException, levée derrière le verrou
     *     pessimiste du service, traduit cette concurrence.
     *
     * Le succès déclenche l'envoi d'un e-mail de confirmation (via ReservationService).
     */
    #[Route('/creneau/{id}/reserver', name: 'app_reservation_nouvelle', methods: ['GET', 'POST'])]
    public function nouveau(Creneau $creneau, Request $request): Response
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        if ($motif = $this->reservationService->motifRefusPrealable($creneau, $utilisateur)) {
            $this->addFlash('error', $this->messageRefus($motif));

            return $this->redirectToRoute('app_creneaux_disponibles');
        }

        $form = $this->createForm(ReservationType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->reservationService->aReservationChevauchante($utilisateur, $creneau)) {
                $this->addFlash('error', 'Vous avez déjà une réservation à ce créneau horaire.');
            } else {
                try {
                    $this->reservationService->reserver(
                        $creneau,
                        $form->get('commentaireAuditeur')->getData(),
                        $utilisateur,
                    );
                    $this->addFlash('success', 'Votre réservation a été confirmée. Un email de confirmation vous est envoyé.');

                    return $this->redirectToRoute('app_mes_reservations');
                } catch (CreneauIndisponibleException) {
                    $this->addFlash('error', 'Ce créneau vient d\'être réservé par quelqu\'un d\'autre.');

                    return $this->redirectToRoute('app_creneaux_disponibles');
                }
            }
        }

        return $this->render('auditeur/reservation/nouveau.html.twig', [
            'creneau'    => $creneau,
            'formulaire' => $form,
        ]);
    }

    private function messageRefus(MotifRefusReservation $motif): string
    {
        return match ($motif) {
            MotifRefusReservation::CreneauInactifOuPasse,
            MotifRefusReservation::ProprietaireInactif => 'Ce créneau n\'est plus disponible.',
            MotifRefusReservation::CreneauDejaReserve  => 'Ce créneau a déjà été réservé.',
            MotifRefusReservation::PropreCreneau       => 'Vous ne pouvez pas réserver votre propre créneau.',
        };
    }
}
