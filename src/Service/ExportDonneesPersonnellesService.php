<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Creneau;
use App\Entity\Notification;
use App\Entity\Reservation;
use App\Entity\Utilisateur;
use App\Repository\CreneauRepository;
use App\Repository\NotificationRepository;
use App\Repository\ReservationRepository;

/**
 * Construit l'export des données personnelles d'un utilisateur (US-5.6),
 * pour le droit d'accès (RGPD art. 15) et la portabilité (art. 20).
 *
 * Minimisation stricte :
 * - JAMAIS le hash du mot de passe ni aucune donnée d'authentification.
 * - Pour un rendez-vous, la contrepartie (Personnel) est exposée par son NOM
 *   complet seul — jamais son email.
 * - Pour les créneaux d'un Personnel, AUCUNE identité d'auditeur ayant réservé.
 * - Pas de journal d'administration (accountability, données de tiers).
 *
 * Dates en ISO 8601 avec offset Réunion (machine-readable, art. 20).
 * Service en lecture pure : aucun persist/flush.
 */
final readonly class ExportDonneesPersonnellesService
{
    private const FUSEAU_REUNION = 'Indian/Reunion';

    public function __construct(
        private ReservationRepository $reservationRepository,
        private NotificationRepository $notificationRepository,
        private CreneauRepository $creneauRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function exporter(Utilisateur $utilisateur): array
    {
        $donnees = [
            'export'        => $this->construireMeta(),
            'profil'        => $this->construireProfil($utilisateur),
            'reservations'  => $this->construireReservations($utilisateur),
            'notifications' => $this->construireNotifications($utilisateur),
        ];

        // Section data-driven : présente uniquement si l'utilisateur possède des
        // créneaux (donc un Personnel / Super-admin), absente pour un Auditeur.
        $creneaux = $this->construireCreneaux($utilisateur);
        if ($creneaux !== []) {
            $donnees['creneaux_proposes'] = $creneaux;
        }

        return $donnees;
    }

    /**
     * @return array<string, mixed>
     */
    private function construireMeta(): array
    {
        return [
            'date'        => $this->formaterDate(new \DateTimeImmutable()),
            'finalite'    => "Droit d'accès et de portabilité (RGPD art. 15 et 20)",
            'application' => 'CreaSlot — Cnam Réunion',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function construireProfil(Utilisateur $utilisateur): array
    {
        return [
            'email'              => $utilisateur->getEmail(),
            'nom'                => $utilisateur->getNom(),
            'prenom'             => $utilisateur->getPrenom(),
            'role'               => $utilisateur->getRole()->libelle(),
            'service'            => $utilisateur->getService()?->getNom(),
            'compte_actif'       => $utilisateur->isEstActif(),
            'date_creation'      => $this->formaterDate($utilisateur->getDateCreation()),
            'derniere_connexion' => $this->formaterDate($utilisateur->getDerniereConnexion()),
            'preferences_email'  => [
                'modification_commentaire' => $utilisateur->isEmailModificationCommentaire(),
                'rappel_j1'                => $utilisateur->isEmailRappelJ1(),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function construireReservations(Utilisateur $utilisateur): array
    {
        return array_values(array_map(
            fn (Reservation $reservation): array => [
                'date_reservation' => $this->formaterDate($reservation->getDateReservation()),
                'statut'           => $reservation->getStatut()->value,
                'commentaire'      => $reservation->getCommentaireAuditeur(),
                'motif_annulation' => $reservation->getMotifAnnulation(),
                'date_annulation'  => $this->formaterDate($reservation->getDateAnnulation()),
                'rendez_vous'      => [
                    'debut' => $this->formaterDate($reservation->getCreneau()->getDateDebut()),
                    'fin'   => $this->formaterDate($reservation->getCreneau()->getDateFin()),
                    'type'  => $reservation->getCreneau()->getTypeRdv()->getLibelle(),
                    'avec'  => $reservation->getCreneau()->getUtilisateur()->getNomComplet(),
                ],
            ],
            $this->reservationRepository->findAllPourExport($utilisateur),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function construireNotifications(Utilisateur $utilisateur): array
    {
        return array_values(array_map(
            fn (Notification $notification): array => [
                'date'    => $this->formaterDate($notification->getDateCreation()),
                'type'    => $notification->getType()->libelle(),
                'titre'   => $notification->getTitre(),
                'message' => $notification->getMessage(),
                'lu'      => $notification->isLu(),
            ],
            $this->notificationRepository->findAllPourExport($utilisateur),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function construireCreneaux(Utilisateur $utilisateur): array
    {
        return array_values(array_map(
            fn (Creneau $creneau): array => [
                'debut' => $this->formaterDate($creneau->getDateDebut()),
                'fin'   => $this->formaterDate($creneau->getDateFin()),
                'type'  => $creneau->getTypeRdv()->getLibelle(),
                'actif' => $creneau->isEstActif(),
            ],
            $this->creneauRepository->findAllParProprietairePourExport($utilisateur),
        ));
    }

    /**
     * Date ISO 8601 avec offset Réunion (ex. 2026-06-04T09:00:00+04:00), null préservé.
     */
    private function formaterDate(?\DateTimeImmutable $date): ?string
    {
        return $date?->setTimezone(new \DateTimeZone(self::FUSEAU_REUNION))->format(\DateTimeInterface::ATOM);
    }
}
