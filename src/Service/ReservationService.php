<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Creneau;
use App\Entity\Reservation;
use App\Entity\Utilisateur;
use App\Enum\MotifRefusReservation;
use App\Enum\StatutReservation;
use App\Exception\CreneauIndisponibleException;
use App\Exception\ReservationNonAnnulableException;
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

    /**
     * Détermine le motif métier qui empêche l'auteur de réserver ce créneau.
     *
     * Garde préalable hors transaction : créneau inactif ou passé, déjà réservé,
     * propriétaire inactif, ou tentative de réserver son propre créneau.
     *
     * @param Creneau     $creneau Le créneau visé par la demande de réservation
     * @param Utilisateur $auteur  L'auditeur qui souhaite réserver
     *
     * @return MotifRefusReservation|null Le motif de refus, ou null si la réservation est permise
     */
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

    /**
     * Indique si l'auteur détient déjà une réservation active dont le créneau
     * chevauche la plage horaire du créneau visé (RG-2, intersection stricte).
     *
     * @param Utilisateur $auteur  L'auditeur dont on inspecte les réservations actives
     * @param Creneau     $creneau Le créneau visé, dont la plage horaire sert de référence
     *
     * @return bool Vrai si une réservation active chevauchante existe déjà
     */
    public function aReservationChevauchante(Utilisateur $auteur, Creneau $creneau): bool
    {
        return $this->reservationRepository->existeReservationActiveEnChevauchement(
            $auteur,
            $creneau->getDateDebut(),
            $creneau->getDateFin(),
        );
    }

    /**
     * Crée une réservation active sur le créneau, sous verrou pessimiste.
     *
     * Le créneau est verrouillé en écriture puis sa disponibilité revérifiée avant
     * l'enregistrement ; les deux courriels partent une fois la transaction validée,
     * afin qu'un échec d'envoi ne remette pas en cause une réservation déjà écrite.
     *
     * @param Creneau     $creneau     Le créneau à réserver
     * @param string|null $commentaire Le commentaire libre de l'auditeur, ou null
     * @param Utilisateur $auteur      L'auditeur qui effectue la réservation
     *
     * @return Reservation La réservation créée, à l'état actif
     *
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

        // Notifications HORS transaction : un echec SMTP ne doit pas annuler une reservation valide
        $this->notificationService->notifierAuditeurReservation($reservation);
        $this->notificationService->notifierPersonnelReservation($reservation);

        return $reservation;
    }

    /**
     * Passe une réservation à l'état annulé, à la demande de son propriétaire ou d'un administrateur.
     *
     * Le statut bascule à ANNULEE en conservant la ligne (soft-delete), après contrôle
     * de l'annulabilité ; le journal ne retient que la longueur du motif, jamais son
     * contenu, par minimisation RGPD.
     *
     * @param Reservation $reservation La réservation active à annuler
     * @param string|null $motif       Le motif d'annulation saisi, ou null
     *
     * @throws ReservationNonAnnulableException si la reservation n'est plus annulable
     */
    public function annuler(Reservation $reservation, ?string $motif): void
    {
        $this->entityManager->beginTransaction();
        try {
            $this->entityManager->refresh($reservation);

            if (!$reservation->isAnnulable()) {
                $this->entityManager->rollback();

                throw new ReservationNonAnnulableException($reservation->getStatut());
            }

            $reservation->annuler($motif);
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (ReservationNonAnnulableException $e) {
            // rollback deja effectue ci-dessus : on relaie sans re-rollback
            throw $e;
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

        // Emails post-flush, hors transaction (Option B : non-propagation des erreurs).
        $this->notificationService->notifierAuditeurAnnulationReservation($reservation);
        $this->notificationService->notifierPersonnelAnnulationReservation($reservation);
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
