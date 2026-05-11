<?php

declare(strict_types=1);

namespace App\Controller\Auditeur;

use App\Entity\Reservation;
use App\Enum\StatutReservation;
use App\Form\AnnulationReservationType;
use App\Security\ReservationVoter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_AUDITEUR')]
final class ReservationAnnulationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface        $logger,
    ) {}

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

        return $this->enregistrerAnnulation(
            $reservation,
            $form->get('motifAnnulation')->getData(),
            $request,
        );
    }

    private function enregistrerAnnulation(
        Reservation $reservation,
        ?string $motif,
        Request $request,
    ): Response {
        $this->entityManager->beginTransaction();
        try {
            $this->entityManager->refresh($reservation);

            if (!$reservation->isAnnulable()) {
                $this->entityManager->rollback();
                $this->addFlash('error', $this->messageRefus($reservation));
                return $this->redirigerVersListe($request);
            }

            $reservation->annuler($motif);
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw $e;
        }

        // Minimisation RGPD : on logue uniquement la longueur du motif (pas le contenu sensible).
        $this->logger->info('Réservation annulée', [
            'reservation_id' => $reservation->getId(),
            'user_id'        => $reservation->getUtilisateur()->getId(),
            'motif_longueur' => strlen($motif ?? ''),
        ]);

        $this->addFlash('success', 'Votre réservation a été annulée. Le créneau est de nouveau disponible.');

        return $this->redirigerVersListe($request);
    }

    private function messageRefus(Reservation $reservation): string
    {
        return $reservation->getStatut() === StatutReservation::ANNULEE
            ? 'Cette réservation a déjà été annulée.'
            : 'Cette réservation est passée, vous ne pouvez plus l\'annuler.';
    }

    /**
     * Préserve le filtre actif si le Referer pointe vers la liste des réservations,
     * sinon redirige vers la route nue. Validation contre l'open-redirect :
     * on n'utilise que le path extrait (jamais le host) et on vérifie qu'il
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
