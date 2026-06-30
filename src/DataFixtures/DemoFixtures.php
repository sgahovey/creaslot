<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Creneau;
use App\Entity\Notification;
use App\Entity\Reservation;
use App\Entity\Service;
use App\Entity\TypeRdv;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Enum\StatutReservation;
use App\Enum\TypeNotification;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Données fictives de démonstration (personnel, auditeurs, admin de démo,
 * créneaux, réservations, notifications) destinées à la préproduction et au
 * développement uniquement. S'appuie sur les références exposées par
 * ReferenceFixtures (Services et types de rendez-vous).
 */
class DemoFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $personnels = $this->creerPersonnel($manager);
        $auditeurs = $this->creerAuditeurs($manager);
        $this->creerAdmin($manager);

        $creneaux = $this->creerCreneaux($manager, $personnels);
        $this->creerReservations($manager, $creneaux, $auditeurs);
        $this->creerNotifications($manager, $auditeurs);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [ReferenceFixtures::class];
    }

    public static function getGroups(): array
    {
        return ['demo'];
    }

    /** @return Utilisateur[] */
    private function creerPersonnel(ObjectManager $manager): array
    {
        $donnees = [
            ['Marie',     'Dupont',   'creaslotdemo+marie@gmail.com',    ReferenceFixtures::PREFIXE_SERVICE . '0'],
            ['Jean',      'Martin',   'creaslotdemo+jean@gmail.com',     ReferenceFixtures::PREFIXE_SERVICE . '1'],
            ['Sophie',    'Lefevre',  'creaslotdemo+sophie@gmail.com',   ReferenceFixtures::PREFIXE_SERVICE . '2'],
        ];

        $personnels = [];
        foreach ($donnees as [$prenom, $nom, $email, $referenceService]) {
            $service = $this->getReference($referenceService, Service::class);

            $utilisateur = new Utilisateur();
            $utilisateur->setEmail($email)
                        ->setPrenom($prenom)
                        ->setNom($nom)
                        ->setRole(RoleUtilisateur::PERSONNEL)
                        ->setEstActif(true)
                        ->setService($service)
                        ->setMotDePasseHash(
                            $this->passwordHasher->hashPassword($utilisateur, 'password'),
                        );
            $manager->persist($utilisateur);
            $personnels[] = $utilisateur;
        }

        return $personnels;
    }

    /** @return Utilisateur[] */
    private function creerAuditeurs(ObjectManager $manager): array
    {
        $donnees = [
            ['Xavier',    'Dijoux',   'creaslotdemo+xavier@gmail.com'],
            ['Julie',     'Potier',   'creaslotdemo+julie@gmail.com'],
            ['Timothée',  'Perez',    'creaslotdemo+timothee@gmail.com'],
            ['Célina',    'Pasquier', 'creaslotdemo+celina@gmail.com'],
            ['Margot',    'Robin',    'creaslotdemo+margot@gmail.com'],
        ];

        $auditeurs = [];
        foreach ($donnees as [$prenom, $nom, $email]) {
            $utilisateur = new Utilisateur();
            $utilisateur->setEmail($email)
                        ->setPrenom($prenom)
                        ->setNom($nom)
                        ->setRole(RoleUtilisateur::AUDITEUR)
                        ->setEstActif(true)
                        ->setMotDePasseHash(
                            $this->passwordHasher->hashPassword($utilisateur, 'password'),
                        );
            $manager->persist($utilisateur);
            $auditeurs[] = $utilisateur;
        }

        // US-4.8 : démo E2E — Julie a désactivé l'email de rappel J-1 (illustre
        // le filtrage RGPD). Les autres Auditeurs gardent les défauts (true, F1).
        $auditeurs[1]->setEmailRappelJ1(false);

        return $auditeurs;
    }

    private function creerAdmin(ObjectManager $manager): void
    {
        $admin = new Utilisateur();
        $admin->setEmail('creaslotdemo+admin@gmail.com')
              ->setPrenom('Super')
              ->setNom('Admin')
              ->setRole(RoleUtilisateur::SUPER_ADMIN)
              ->setEstActif(true)
              ->setMotDePasseHash(
                  $this->passwordHasher->hashPassword($admin, 'password'),
              );
        $manager->persist($admin);
    }

    /**
     * @param Utilisateur[] $personnels
     *
     * @return Creneau[]
     */
    private function creerCreneaux(ObjectManager $manager, array $personnels): array
    {
        $now = new \DateTimeImmutable();
        $creneaux = [];

        $donnees = [
            // [offsetJours, offsetHeures, commentaire]
            [7,   9,  'Disponible pour questions sur votre dossier d\'alternance'],
            [7,   11, null],
            [8,   9,  'Premier entretien de suivi'],
            [8,   14, null],
            [10,  10, 'Rendez-vous de bilan de fin de module'],
            [14,  9,  null],
            [14,  14, 'Entretien sur les modalités de financement'],
            [21,  9,  null],
            [21,  11, 'Questions administratives diverses'],
            [-3,  10, 'Créneau passé — archivé'],
        ];

        foreach ($donnees as $index => [$offsetJours, $offsetHeures, $commentaire]) {
            $dateDebut = $now
                ->setTime($offsetHeures, 0)
                ->modify(sprintf('%+d days', $offsetJours));
            $dateFin = $dateDebut->modify('+1 hour');

            $type = $this->getReference(ReferenceFixtures::PREFIXE_TYPE . ($index % 3), TypeRdv::class);

            $creneau = new Creneau();
            $creneau->setDateDebut($dateDebut)
                    ->setDateFin($dateFin)
                    ->setCommentaireAuditeur($commentaire)
                    ->setEstActif(true)
                    ->setUtilisateur($personnels[$index % 3])
                    ->setTypeRdv($type);
            $manager->persist($creneau);
            $creneaux[] = $creneau;
        }

        return $creneaux;
    }

    /**
     * @param Creneau[]     $creneaux
     * @param Utilisateur[] $auditeurs
     */
    private function creerReservations(
        ObjectManager $manager,
        array $creneaux,
        array $auditeurs,
    ): void {
        // Réservation 1 — ACTIVE
        $reservation1 = new Reservation();
        $reservation1->setCreneau($creneaux[0])
                     ->setUtilisateur($auditeurs[0])
                     ->setStatut(StatutReservation::ACTIVE)
                     ->setCommentaireAuditeur('Je souhaite faire le point sur mon dossier d\'alternance.');
        $manager->persist($reservation1);

        // Réservation 2 — ACTIVE
        $reservation2 = new Reservation();
        $reservation2->setCreneau($creneaux[2])
                     ->setUtilisateur($auditeurs[1])
                     ->setStatut(StatutReservation::ACTIVE)
                     ->setCommentaireAuditeur(null);
        $manager->persist($reservation2);

        // Réservation 3 — ANNULEE avec motif
        $reservation3 = new Reservation();
        $reservation3->setCreneau($creneaux[4])
                     ->setUtilisateur($auditeurs[2])
                     ->setStatut(StatutReservation::ANNULEE)
                     ->setCommentaireAuditeur('Demande de bilan de fin de module.')
                     ->setMotifAnnulation('Indisponibilité imprévue de l\'auditeur.')
                     ->setDateAnnulation(new \DateTimeImmutable('-1 day'));
        $manager->persist($reservation3);
    }

    /**
     * Notifications in-app de démo (US-4.7), pour visualiser la page
     * « Mes notifications » et le badge non-lues côté Auditeur.
     *
     * Données illustratives (non liées à une Reservation : le champ est nullable).
     * Xavier (compte de test principal) reçoit 4 notifications de types variés
     * dont 2 non lues ; Julie en reçoit 1.
     *
     * @param Utilisateur[] $auditeurs
     */
    private function creerNotifications(ObjectManager $manager, array $auditeurs): void
    {
        $xavier = $auditeurs[0];
        $julie = $auditeurs[1];

        $donnees = [
            [$xavier, TypeNotification::CONFIRMATION_RESERVATION, 'Réservation confirmée',
                'Votre rendez-vous avec Marie Dupont le 06/06/2026 à 10h00 a été confirmé.', false],
            [$xavier, TypeNotification::RAPPEL_J1, 'Rappel : rendez-vous demain',
                'N\'oubliez pas votre rendez-vous demain, le 06/06/2026 à 10h00, avec Marie Dupont.', false],
            [$xavier, TypeNotification::MODIFICATION_COMMENTAIRE, 'Modification du créneau',
                'Le commentaire de votre rendez-vous du 06/06/2026 à 10h00 a été modifié par Marie Dupont.', true],
            [$xavier, TypeNotification::ANNULATION_RESERVATION, 'Réservation annulée',
                'Votre rendez-vous avec Jean Martin le 05/06/2026 à 14h00 a été annulé. Motif : Indisponibilité du conseiller.', true],
            [$julie, TypeNotification::SUPPRESSION_CRENEAU, 'Créneau supprimé',
                'Votre rendez-vous du 04/06/2026 à 09h00 avec Sophie Lefevre a été annulé : le créneau a été supprimé.', false],
        ];

        foreach ($donnees as [$destinataire, $type, $titre, $message, $lu]) {
            $notification = (new Notification())
                ->setDestinataire($destinataire)
                ->setType($type)
                ->setTitre($titre)
                ->setMessage($message)
                ->setLu($lu);
            $manager->persist($notification);
        }
    }
}
