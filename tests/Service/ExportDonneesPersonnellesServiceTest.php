<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Creneau;
use App\Entity\Notification;
use App\Entity\Reservation;
use App\Entity\TypeRdv;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Enum\StatutReservation;
use App\Enum\TypeNotification;
use App\Repository\CreneauRepository;
use App\Repository\NotificationRepository;
use App\Repository\ReservationRepository;
use App\Service\ExportDonneesPersonnellesService;
use PHPUnit\Framework\TestCase;

/**
 * Test unitaire du service d'export RGPD (US-5.6).
 *
 * Vérifie la structure et — surtout — la minimisation : le hash et l'email de la
 * contrepartie (Personnel) ne figurent JAMAIS dans le JSON ; pas de section
 * journal ; les créneaux ne sont présents que pour un Personnel ; dates ISO 8601.
 */
final class ExportDonneesPersonnellesServiceTest extends TestCase
{
    private const HASH_SECRET = 'HASH_SECRET_NE_DOIT_PAS_FUITER';
    private const EMAIL_PERSONNEL = 'personnel-secret@cnam.re';

    public function test_export_auditeur_minimise_et_n_expose_ni_hash_ni_email_personnel(): void
    {
        $auditeur = $this->creerUtilisateur(1, 'Anna', 'Auditeur', 'anna@test.re', RoleUtilisateur::AUDITEUR);
        $reservation = $this->creerReservation($auditeur, $this->creerPersonnelEtCreneau());

        $donnees = $this->creerService(
            reservations: [$reservation],
            notifications: [$this->creerNotification($auditeur)],
            creneaux: [], // un Auditeur ne possède pas de créneaux
        )->exporter($auditeur);

        // Sections attendues, et PAS de section journal ni créneaux pour un Auditeur.
        self::assertSame(['export', 'profil', 'reservations', 'notifications'], array_keys($donnees));
        self::assertArrayNotHasKey('creneaux_proposes', $donnees);
        self::assertArrayNotHasKey('journal', $donnees);

        // Profil minimisé, email de l'auditeur présent.
        self::assertSame('anna@test.re', $donnees['profil']['email']);

        // La contrepartie du RDV n'expose que le nom du Personnel.
        self::assertSame('Paul Personnel', $donnees['reservations'][0]['rendez_vous']['avec']);

        // Le JSON encodé ne contient NI le hash NI l'email du Personnel.
        $json = json_encode($donnees, JSON_UNESCAPED_UNICODE);
        self::assertIsString($json, 'Le profil exporté doit être sérialisable en JSON.');
        self::assertStringNotContainsString(self::HASH_SECRET, $json);
        self::assertStringNotContainsString(self::EMAIL_PERSONNEL, $json);
        self::assertStringContainsString('Paul Personnel', $json);

        // Dates en ISO 8601 avec offset Réunion.
        self::assertStringContainsString('+04:00', $donnees['profil']['date_creation']);
    }

    public function test_export_personnel_inclut_ses_creneaux(): void
    {
        $personnel = $this->creerUtilisateur(2, 'Paul', 'Personnel', self::EMAIL_PERSONNEL, RoleUtilisateur::PERSONNEL);

        $donnees = $this->creerService(
            reservations: [],
            notifications: [],
            creneaux: [$this->creerCreneau($personnel)],
        )->exporter($personnel);

        self::assertArrayHasKey('creneaux_proposes', $donnees);
        self::assertCount(1, $donnees['creneaux_proposes']);
        self::assertSame('Présentiel', $donnees['creneaux_proposes'][0]['type']);
    }

    /**
     * @param list<Reservation>  $reservations
     * @param list<Notification> $notifications
     * @param list<Creneau>      $creneaux
     */
    private function creerService(array $reservations, array $notifications, array $creneaux): ExportDonneesPersonnellesService
    {
        $reservationRepository = $this->createStub(ReservationRepository::class);
        $reservationRepository->method('findAllPourExport')->willReturn($reservations);

        $notificationRepository = $this->createStub(NotificationRepository::class);
        $notificationRepository->method('findAllPourExport')->willReturn($notifications);

        $creneauRepository = $this->createStub(CreneauRepository::class);
        $creneauRepository->method('findAllParProprietairePourExport')->willReturn($creneaux);

        return new ExportDonneesPersonnellesService($reservationRepository, $notificationRepository, $creneauRepository);
    }

    private function creerPersonnelEtCreneau(): Creneau
    {
        $personnel = $this->creerUtilisateur(2, 'Paul', 'Personnel', self::EMAIL_PERSONNEL, RoleUtilisateur::PERSONNEL);

        return $this->creerCreneau($personnel);
    }

    private function creerCreneau(Utilisateur $personnel): Creneau
    {
        $type = (new TypeRdv())->setCode('PRES')->setLibelle('Présentiel')->setCouleurHex('#28A745')->setEstActif(true);

        return (new Creneau())
            ->setUtilisateur($personnel)
            ->setTypeRdv($type)
            ->setDateDebut(new \DateTimeImmutable('2026-06-04 09:00'))
            ->setDateFin(new \DateTimeImmutable('2026-06-04 10:00'))
            ->setEstActif(true);
    }

    private function creerReservation(Utilisateur $auditeur, Creneau $creneau): Reservation
    {
        return (new Reservation())
            ->setCreneau($creneau)
            ->setUtilisateur($auditeur)
            ->setStatut(StatutReservation::ACTIVE)
            ->setCommentaireAuditeur('Mon commentaire');
    }

    private function creerNotification(Utilisateur $destinataire): Notification
    {
        return (new Notification())
            ->setDestinataire($destinataire)
            ->setType(TypeNotification::CONFIRMATION_RESERVATION)
            ->setTitre('Réservation confirmée')
            ->setMessage('Votre rendez-vous a été confirmé.')
            ->setLu(false);
    }

    private function creerUtilisateur(int $id, string $prenom, string $nom, string $email, RoleUtilisateur $role): Utilisateur
    {
        $utilisateur = (new Utilisateur())
            ->setEmail($email)
            ->setPrenom($prenom)
            ->setNom($nom)
            ->setRole($role)
            ->setEstActif(true)
            ->setMotDePasseHash(self::HASH_SECRET);

        $prop = new \ReflectionProperty(Utilisateur::class, 'id');
        $prop->setValue($utilisateur, $id);

        return $utilisateur;
    }
}
