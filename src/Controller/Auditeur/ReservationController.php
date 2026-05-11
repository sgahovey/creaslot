<?php

declare(strict_types=1);

namespace App\Controller\Auditeur;

use App\Entity\Creneau;
use App\Entity\Reservation;
use App\Entity\Utilisateur;
use App\Enum\StatutReservation;
use App\Form\ReservationType;
use App\Repository\ReservationRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_AUDITEUR')]
class ReservationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ReservationRepository  $reservationRepository,
        private readonly LoggerInterface        $logger,
    ) {}

    #[Route('/creneau/{id}/reserver', name: 'app_reservation_nouvelle', methods: ['GET', 'POST'])]
    public function nouveau(Creneau $creneau, Request $request): Response
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        if ($refus = $this->refusSiNonDisponible($creneau, $utilisateur)) {
            return $refus;
        }

        $form = $this->createForm(ReservationType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->aDejaReservationChevauchante($utilisateur, $creneau)) {
                $this->addFlash('error', 'Vous avez déjà une réservation à ce créneau horaire.');
            } else {
                return $this->enregistrerReservation(
                    $creneau,
                    $form->get('commentaireAuditeur')->getData(),
                    $utilisateur,
                );
            }
        }

        return $this->render('auditeur/reservation/nouveau.html.twig', [
            'creneau'    => $creneau,
            'formulaire' => $form,
        ]);
    }

    private function refusSiNonDisponible(Creneau $creneau, Utilisateur $utilisateur): ?Response
    {
        if (!$creneau->isEstActif() || $creneau->isPasse()) {
            $this->addFlash('error', 'Ce créneau n\'est plus disponible.');
            return $this->redirectToRoute('app_creneaux_disponibles');
        }

        if ($creneau->isReserve()) {
            $this->addFlash('error', 'Ce créneau a déjà été réservé.');
            return $this->redirectToRoute('app_creneaux_disponibles');
        }

        if (!$creneau->getUtilisateur()->isEstActif()) {
            $this->addFlash('error', 'Ce créneau n\'est plus disponible.');
            return $this->redirectToRoute('app_creneaux_disponibles');
        }

        if ($creneau->getUtilisateur() === $utilisateur) {
            $this->addFlash('error', 'Vous ne pouvez pas réserver votre propre créneau.');
            return $this->redirectToRoute('app_creneaux_disponibles');
        }

        return null;
    }

    private function aDejaReservationChevauchante(Utilisateur $utilisateur, Creneau $creneau): bool
    {
        return $this->reservationRepository->existeReservationActiveEnChevauchement(
            $utilisateur,
            $creneau->getDateDebut(),
            $creneau->getDateFin(),
        );
    }

    private function enregistrerReservation(
        Creneau $creneau,
        ?string $commentaire,
        Utilisateur $utilisateur,
    ): Response {
        $this->entityManager->beginTransaction();
        try {
            // Verrouillage pessimiste : empêche la double réservation simultanée
            $this->entityManager->lock($creneau, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($creneau);

            if (!$this->creneauEstEncoreDisponible($creneau)) {
                $this->entityManager->rollback();
                $this->addFlash('error', 'Ce créneau vient d\'être réservé par quelqu\'un d\'autre.');
                return $this->redirectToRoute('app_creneaux_disponibles');
            }

            $reservation = $this->creerReservation($creneau, $commentaire, $utilisateur);
            $this->entityManager->persist($reservation);
            $this->entityManager->flush();

            $this->logger->info('Réservation créée', [
                'reservation_id' => $reservation->getId(),
                'user_id'        => $utilisateur->getId(),
                'creneau_id'     => $creneau->getId(),
            ]);

            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw $e;
        }

        $this->addFlash('success', 'Votre réservation a été confirmée. Vous recevrez un email de confirmation.');
        // TODO: Rediriger vers app_mes_reservations après US-3.3
        return $this->redirectToRoute('app_creneaux_disponibles');
    }

    private function creneauEstEncoreDisponible(Creneau $creneau): bool
    {
        return $creneau->isEstActif() && !$creneau->isPasse() && !$creneau->isReserve();
    }

    private function creerReservation(
        Creneau $creneau,
        ?string $commentaire,
        Utilisateur $utilisateur,
    ): Reservation {
        return (new Reservation())
            ->setCreneau($creneau)
            ->setUtilisateur($utilisateur)
            ->setCommentaireAuditeur($commentaire)
            ->setStatut(StatutReservation::ACTIVE);
    }
}
