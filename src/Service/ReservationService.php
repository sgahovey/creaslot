<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Creneau;
use App\Entity\Reservation;
use App\Entity\Utilisateur;
use App\Enum\MotifRefusReservation;
use App\Enum\StatutReservation;
use App\Exception\CreneauIndisponibleException;
use App\Repository\ReservationRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ReservationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ReservationRepository $reservationRepository,
        private readonly LoggerInterface $logger,
        private readonly NotificationService $notificationService,
    ) {
    }

    public function motifRefusPrealable(Creneau $creneau, Utilisateur $auteur): ?MotifRefusReservation
    {
        if (!$creneau->isEstActif() || $creneau->isPasse()) {
            return MotifRefusReservation::CreneauInactifOuPasse;
        }

        if ($creneau->isReserve()) {
            return MotifRefusReservation::CreneauDejaReserve;
        }

        if (!$creneau->getUtilisateur()->isEstActif()) {
            return MotifRefusReservation::ProprietaireInactif;
        }

        if ($creneau->getUtilisateur() === $auteur) {
            return MotifRefusReservation::PropreCreneau;
        }

        return null;
    }

    public function aReservationChevauchante(Utilisateur $auteur, Creneau $creneau): bool
    {
        return $this->reservationRepository->existeReservationActiveEnChevauchement(
            $auteur,
            $creneau->getDateDebut(),
            $creneau->getDateFin(),
        );
    }

    /**
     * @throws CreneauIndisponibleException si le creneau vient d'etre reserve (concurrence)
     */
    public function reserver(Creneau $creneau, ?string $commentaire, Utilisateur $auteur): Reservation
    {
        $this->entityManager->beginTransaction();
        try {
            // Verrouillage pessimiste : empeche la double reservation simultanee
            $this->entityManager->lock($creneau, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($creneau);

            if (!$this->creneauEstEncoreDisponible($creneau)) {
                $this->entityManager->rollback();

                throw new CreneauIndisponibleException();
            }

            $reservation = $this->creerReservation($creneau, $commentaire, $auteur);
            $this->entityManager->persist($reservation);
            $this->entityManager->flush();

            $this->logger->info('Réservation créée', [
                'reservation_id' => $reservation->getId(),
                'user_id'        => $auteur->getId(),
                'creneau_id'     => $creneau->getId(),
            ]);

            $this->entityManager->commit();
        } catch (CreneauIndisponibleException $e) {
            // rollback deja effectue ci-dessus : on relaie sans re-rollback
            throw $e;
        } catch (\Throwable $e) {
            $this->entityManager->rollback();

            throw $e;
        }

        // Notifications HORS transaction (Option B : un echec SMTP ne rollback pas la reservation).
        $this->notificationService->notifierAuditeurReservation($reservation);
        $this->notificationService->notifierPersonnelReservation($reservation);

        return $reservation;
    }

    private function creneauEstEncoreDisponible(Creneau $creneau): bool
    {
        return $creneau->isEstActif() && !$creneau->isPasse() && !$creneau->isReserve();
    }

    private function creerReservation(
        Creneau $creneau,
        ?string $commentaire,
        Utilisateur $auteur,
    ): Reservation {
        return (new Reservation())
            ->setCreneau($creneau)
            ->setUtilisateur($auteur)
            ->setCommentaireAuditeur($commentaire)
            ->setStatut(StatutReservation::ACTIVE);
    }
}
