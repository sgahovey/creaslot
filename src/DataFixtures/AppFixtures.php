<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Creneau;
use App\Entity\Reservation;
use App\Entity\Service;
use App\Entity\TypeRdv;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Enum\StatutReservation;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    public function load(ObjectManager $manager): void
    {
        $services  = $this->creerServices($manager);
        $types     = $this->creerTypesRdv($manager);
        $personnels = $this->creerPersonnel($manager, $services);
        $auditeurs  = $this->creerAuditeurs($manager);
        $this->creerAdmin($manager);

        $creneaux = $this->creerCreneaux($manager, $personnels, $types);
        $this->creerReservations($manager, $creneaux, $auditeurs);

        $manager->flush();
    }

    /** @return Service[] */
    private function creerServices(ObjectManager $manager): array
    {
        $donnees = [
            ['Service Commercial', 'Responsables commerciaux du Cnam'],
            ['Service Alternance', 'Gestionnaires de l\'alternance'],
            ['Accueil',            'Accueil et orientation des auditeurs'],
        ];

        $services = [];
        foreach ($donnees as [$nom, $description]) {
            $service = new Service();
            $service->setNom($nom)->setDescription($description)->setEstActif(true);
            $manager->persist($service);
            $services[] = $service;
        }

        return $services;
    }

    /** @return TypeRdv[] */
    private function creerTypesRdv(ObjectManager $manager): array
    {
        $donnees = [
            ['PRESENTIEL', 'Présentiel', '#28A745', 'bi-geo-alt',      'Rendez-vous en présentiel au Cnam Réunion'],
            ['VISIO',      'Visio',      '#FD7E14', 'bi-camera-video', 'Rendez-vous en visioconférence'],
            ['TELEPHONE',  'Téléphone',  '#007BFF', 'bi-telephone',    'Rendez-vous par téléphone'],
        ];

        $types = [];
        foreach ($donnees as [$code, $libelle, $couleur, $icone, $description]) {
            $type = new TypeRdv();
            $type->setCode($code)
                 ->setLibelle($libelle)
                 ->setCouleurHex($couleur)
                 ->setIcone($icone)
                 ->setDescription($description)
                 ->setEstActif(true);
            $manager->persist($type);
            $types[] = $type;
        }

        return $types;
    }

    /**
     * @param  Service[] $services
     * @return Utilisateur[]
     */
    private function creerPersonnel(ObjectManager $manager, array $services): array
    {
        $donnees = [
            ['Marie',     'Dupont',   'marie.dupont@cnam-reunion.fr',    $services[0]],
            ['Jean',      'Martin',   'jean.martin@cnam-reunion.fr',     $services[1]],
            ['Sophie',    'Lefevre',  'sophie.lefevre@cnam-reunion.fr',  $services[2]],
        ];

        $personnels = [];
        foreach ($donnees as [$prenom, $nom, $email, $service]) {
            $utilisateur = new Utilisateur();
            $utilisateur->setEmail($email)
                        ->setPrenom($prenom)
                        ->setNom($nom)
                        ->setRole(RoleUtilisateur::PERSONNEL)
                        ->setEstActif(true)
                        ->setService($service)
                        ->setMotDePasseHash(
                            $this->passwordHasher->hashPassword($utilisateur, 'password')
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
            ['Xavier',    'Dijoux',   'xavier.dijoux@auditeur.cnam-reunion.fr'],
            ['Julie',     'Potier',   'julie.potier@auditeur.cnam-reunion.fr'],
            ['Timothée',  'Perez',    'timothee.perez@auditeur.cnam-reunion.fr'],
            ['Célina',    'Pasquier', 'celina.pasquier@auditeur.cnam-reunion.fr'],
            ['Margot',    'Robin',    'margot.robin@auditeur.cnam-reunion.fr'],
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
                            $this->passwordHasher->hashPassword($utilisateur, 'password')
                        );
            $manager->persist($utilisateur);
            $auditeurs[] = $utilisateur;
        }

        return $auditeurs;
    }

    private function creerAdmin(ObjectManager $manager): void
    {
        $admin = new Utilisateur();
        $admin->setEmail('admin@cnam-reunion.fr')
              ->setPrenom('Super')
              ->setNom('Admin')
              ->setRole(RoleUtilisateur::SUPER_ADMIN)
              ->setEstActif(true)
              ->setMotDePasseHash(
                  $this->passwordHasher->hashPassword($admin, 'password')
              );
        $manager->persist($admin);
    }

    /**
     * @param  Utilisateur[] $personnels
     * @param  TypeRdv[]     $types
     * @return Creneau[]
     */
    private function creerCreneaux(
        ObjectManager $manager,
        array $personnels,
        array $types,
    ): array {
        $now      = new \DateTimeImmutable();
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
            $dateFin   = $dateDebut->modify('+1 hour');

            $creneau = new Creneau();
            $creneau->setDateDebut($dateDebut)
                    ->setDateFin($dateFin)
                    ->setCommentaireAuditeur($commentaire)
                    ->setEstActif(true)
                    ->setUtilisateur($personnels[$index % 3])
                    ->setTypeRdv($types[$index % 3]);
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
}
