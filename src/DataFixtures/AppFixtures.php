<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Creneau;
use App\Entity\Reservation;
use App\Entity\Service;
use App\Entity\TypeRdv;
use App\Entity\Utilisateur;
use App\Enum\StatutCreneau;
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
        // ── Services ────────────────────────────────────────────────────────
        $services = [];
        foreach ([
            ['Comptabilité', 'Assistance comptable et fiscale'],
            ['Juridique',    'Conseil juridique et contentieux'],
            ['RH',           'Ressources humaines et recrutement'],
        ] as [$nom, $desc]) {
            $s = new Service();
            $s->setNom($nom)->setDescription($desc)->setEstActif(true);
            $manager->persist($s);
            $services[] = $s;
        }

        // ── Types de rendez-vous ─────────────────────────────────────────────
        $types = [];
        foreach ([
            ['Entretien initial', '#4F46E5'],
            ['Suivi de dossier',  '#0EA5E9'],
            ['Consultation',      '#10B981'],
        ] as [$libelle, $couleur]) {
            $t = new TypeRdv();
            $t->setLibelle($libelle)->setCouleurHex($couleur)->setEstActif(true);
            $manager->persist($t);
            $types[] = $t;
        }

        // ── Personnel (3 conseillers) ────────────────────────────────────────
        $personnels = [];
        foreach ([
            ['alice@creaslot.test',   'Alice',   'Martin',   ['ROLE_PERSONNEL']],
            ['bob@creaslot.test',     'Bob',     'Dupont',   ['ROLE_PERSONNEL']],
            ['charlie@creaslot.test', 'Charlie', 'Lefevre',  ['ROLE_PERSONNEL']],
        ] as [$email, $prenom, $nom, $roles]) {
            $u = new Utilisateur();
            $u->setEmail($email)
              ->setPrenom($prenom)
              ->setNom($nom)
              ->setRoles($roles)
              ->setEstActif(true)
              ->setMotDePasse($this->passwordHasher->hashPassword($u, 'personnel123'));
            $manager->persist($u);
            $personnels[] = $u;
        }

        // ── Auditeurs (5 clients) ────────────────────────────────────────────
        $auditeurs = [];
        foreach ([
            ['david@example.test',   'David',   'Bernard'],
            ['emma@example.test',    'Emma',    'Petit'],
            ['frank@example.test',   'Frank',   'Robert'],
            ['grace@example.test',   'Grace',   'Simon'],
            ['henry@example.test',   'Henry',   'Laurent'],
        ] as [$email, $prenom, $nom]) {
            $u = new Utilisateur();
            $u->setEmail($email)
              ->setPrenom($prenom)
              ->setNom($nom)
              ->setRoles(['ROLE_AUDITEUR'])
              ->setEstActif(true)
              ->setMotDePasse($this->passwordHasher->hashPassword($u, 'auditeur123'));
            $manager->persist($u);
            $auditeurs[] = $u;
        }

        // ── Super-admin ──────────────────────────────────────────────────────
        $admin = new Utilisateur();
        $admin->setEmail('admin@creaslot.test')
              ->setPrenom('Super')
              ->setNom('Admin')
              ->setRoles(['ROLE_ADMIN', 'ROLE_PERSONNEL'])
              ->setEstActif(true)
              ->setMotDePasse($this->passwordHasher->hashPassword($admin, 'admin1234'));
        $manager->persist($admin);

        // ── Créneaux (10 créneaux) ───────────────────────────────────────────
        $now   = new \DateTimeImmutable('2026-05-10 09:00:00');
        $slots = [];

        for ($i = 0; $i < 10; ++$i) {
            $debut  = $now->modify(sprintf('+%d hours', $i * 2));
            $fin    = $debut->modify('+1 hour');
            $statut = $i < 7 ? StatutCreneau::DISPONIBLE : StatutCreneau::RESERVE;

            $c = new Creneau();
            $c->setDebutAt($debut)
              ->setFinAt($fin)
              ->setStatut($statut)
              ->setEstActif(true)
              ->setPersonnel($personnels[$i % 3])
              ->setService($services[$i % 3])
              ->setTypeRdv($types[$i % 3]);
            $manager->persist($c);
            $slots[] = $c;
        }

        // ── Réservations (3 réservations sur les créneaux RESERVE) ───────────
        $reservationData = [
            [$slots[7],  $auditeurs[0], StatutReservation::ACTIVE,  'Premier rdv de suivi', null,                null],
            [$slots[8],  $auditeurs[1], StatutReservation::HONOREE, 'Dossier fiscal 2025',  null,                null],
            [$slots[9],  $auditeurs[2], StatutReservation::ANNULEE, 'Demande initiale',     'Indisponibilité',   new \DateTimeImmutable('2026-05-09 14:00:00')],
        ];

        foreach ($reservationData as [$creneau, $auditeur, $statut, $commentaire, $motif, $annuleeAt]) {
            $r = new Reservation();
            $r->setCreneau($creneau)
              ->setAuditeur($auditeur)
              ->setStatut($statut)
              ->setCommentaire($commentaire)
              ->setMotifAnnulation($motif)
              ->setAnnuleeAt($annuleeAt);
            $manager->persist($r);
        }

        $manager->flush();
    }
}
