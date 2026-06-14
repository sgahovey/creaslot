<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\CollegueDTO;
use App\Entity\Creneau;
use App\Entity\Reservation;
use App\Entity\Service;
use App\Entity\TypeRdv;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Enum\StatutReservation;
use App\Service\CollegueService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test de caractérisation de CollegueService::getCollegues (DT-10).
 *
 * Fige le comportement OBSERVABLE actuel (statut, heureFinRdv, prochainRdvDate, tri,
 * visibilité, filtres) AVANT l'optimisation du N+1, pour garantir un comportement
 * strictement identique après refacto.
 *
 * Isolation : tout est créé dans une transaction rollbackée en tearDown. Les comptes
 * existants (fixtures) sont désactivés en setUp afin que `findOtherPersonnel` (filtre
 * `estActif = true`) ne renvoie QUE les collègues construits par chaque test — count
 * et ordre déterministes, sans suppression destructive ni problème de FK.
 */
final class CollegueServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CollegueService $collegueService;
    private \DateTimeImmutable $maintenant;

    private TypeRdv $typeRdv;
    private Utilisateur $auditeur;
    private Utilisateur $current;
    private int $compteur = 0;

    protected function setUp(): void
    {
        self::bootKernel(['environment' => 'test']);
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->collegueService = $container->get(CollegueService::class);
        $this->maintenant = new \DateTimeImmutable();

        $this->entityManager->beginTransaction();

        // Tout compte préexistant est masqué de la liste (findOtherPersonnel filtre
        // sur estActif = true) sans rien supprimer : jeu déterministe, rollback en fin.
        $this->entityManager->createQuery('UPDATE App\Entity\Utilisateur u SET u.estActif = false')->execute();

        $this->typeRdv = (new TypeRdv())
            ->setCode('DT10-' . uniqid())
            ->setLibelle('Présentiel')
            ->setCouleurHex('#123456');
        $this->entityManager->persist($this->typeRdv);

        $this->auditeur = $this->creerUtilisateur('Auditeur', RoleUtilisateur::AUDITEUR, null);
        $this->current = $this->creerUtilisateur('Courant', RoleUtilisateur::PERSONNEL, null);

        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        $this->entityManager->close();
        parent::tearDown();
    }

    public function test_collegue_en_cours_reserve_est_en_rdv_avec_heure_de_fin(): void
    {
        $collegue = $this->creerUtilisateur('EnRdv', RoleUtilisateur::PERSONNEL, null);
        $enCoursFin = $this->maintenant->modify('+1 hour');
        $this->reserver($this->creerCreneau($collegue, $this->maintenant->modify('-1 hour'), $enCoursFin));
        // Un prochain RDV réservé futur, pour figer aussi prochainRdvDate quand EN_RDV.
        $prochainDebut = $this->maintenant->modify('+2 days');
        $this->reserver($this->creerCreneau($collegue, $prochainDebut, $prochainDebut->modify('+1 hour')));
        $this->entityManager->flush();

        $dtos = $this->collegueService->getCollegues($this->current, null, false);

        self::assertCount(1, $dtos);
        self::assertSame(CollegueService::STATUT_EN_RDV, $dtos[0]->statut);
        self::assertSame($enCoursFin->format('H\hi'), $dtos[0]->heureFinRdv);
        self::assertNotNull($dtos[0]->prochainRdvDate);
        self::assertSame(
            $prochainDebut->format('Y-m-d H:i:s'),
            $dtos[0]->prochainRdvDate->format('Y-m-d H:i:s'),
        );
    }

    public function test_collegue_avec_prochain_rdv_futur_est_libre_avec_la_date_la_plus_proche(): void
    {
        $collegue = $this->creerUtilisateur('Futur', RoleUtilisateur::PERSONNEL, null);
        $plusProche = $this->maintenant->modify('+1 day');
        $plusLoin = $this->maintenant->modify('+3 days');
        // Ordre d'insertion volontairement « loin puis proche » : on vérifie le MIN.
        $this->reserver($this->creerCreneau($collegue, $plusLoin, $plusLoin->modify('+1 hour')));
        $this->reserver($this->creerCreneau($collegue, $plusProche, $plusProche->modify('+1 hour')));
        $this->entityManager->flush();

        $dtos = $this->collegueService->getCollegues($this->current, null, false);

        self::assertCount(1, $dtos);
        self::assertSame(CollegueService::STATUT_LIBRE, $dtos[0]->statut);
        self::assertNull($dtos[0]->heureFinRdv);
        self::assertNotNull($dtos[0]->prochainRdvDate);
        self::assertSame(
            $plusProche->format('Y-m-d H:i:s'),
            $dtos[0]->prochainRdvDate->format('Y-m-d H:i:s'),
        );
    }

    public function test_collegue_avec_creneau_futur_non_reserve_est_visible_libre_sans_prochain_rdv(): void
    {
        $collegue = $this->creerUtilisateur('NonReserve', RoleUtilisateur::PERSONNEL, null);
        $debut = $this->maintenant->modify('+1 day');
        $this->creerCreneau($collegue, $debut, $debut->modify('+1 hour')); // aucune réservation
        $this->entityManager->flush();

        $dtos = $this->collegueService->getCollegues($this->current, null, false);

        self::assertCount(1, $dtos);
        self::assertSame(CollegueService::STATUT_LIBRE, $dtos[0]->statut);
        self::assertNull($dtos[0]->heureFinRdv);
        self::assertNull($dtos[0]->prochainRdvDate);
    }

    public function test_collegue_sans_creneau_est_exclu(): void
    {
        $this->creerUtilisateur('SansCreneau', RoleUtilisateur::PERSONNEL, null);
        $this->entityManager->flush();

        self::assertCount(0, $this->collegueService->getCollegues($this->current, null, false));
    }

    public function test_collegue_avec_creneau_passe_seulement_est_exclu(): void
    {
        $collegue = $this->creerUtilisateur('Passe', RoleUtilisateur::PERSONNEL, null);
        $this->creerCreneau($collegue, $this->maintenant->modify('-2 hours'), $this->maintenant->modify('-1 hour'));
        $this->entityManager->flush();

        self::assertCount(0, $this->collegueService->getCollegues($this->current, null, false));
    }

    public function test_disponibles_only_exclut_les_collegues_en_rdv(): void
    {
        $enRdv = $this->creerUtilisateur('EnRdv', RoleUtilisateur::PERSONNEL, null);
        $enCoursFin = $this->maintenant->modify('+1 hour');
        $this->reserver($this->creerCreneau($enRdv, $this->maintenant->modify('-1 hour'), $enCoursFin));

        $libre = $this->creerUtilisateur('Libre', RoleUtilisateur::PERSONNEL, null);
        $debut = $this->maintenant->modify('+1 day');
        $this->creerCreneau($libre, $debut, $debut->modify('+1 hour'));
        $this->entityManager->flush();

        $dtos = $this->collegueService->getCollegues($this->current, null, true);

        self::assertCount(1, $dtos);
        self::assertSame(CollegueService::STATUT_LIBRE, $dtos[0]->statut);
        self::assertSame($libre->getEmail(), $dtos[0]->utilisateur->getEmail());
    }

    public function test_filtre_service_ne_garde_que_les_collegues_du_service(): void
    {
        $serviceA = $this->creerService('Service A');
        $serviceB = $this->creerService('Service B');

        $collegueA = $this->creerUtilisateur('DansA', RoleUtilisateur::PERSONNEL, $serviceA);
        $collegueB = $this->creerUtilisateur('DansB', RoleUtilisateur::PERSONNEL, $serviceB);
        foreach ([$collegueA, $collegueB] as $collegue) {
            $debut = $this->maintenant->modify('+1 day');
            $this->creerCreneau($collegue, $debut, $debut->modify('+1 hour'));
        }
        $this->entityManager->flush();

        $dtos = $this->collegueService->getCollegues($this->current, (int) $serviceA->getId(), false);

        self::assertCount(1, $dtos);
        self::assertSame($collegueA->getEmail(), $dtos[0]->utilisateur->getEmail());
    }

    public function test_tri_par_nom_de_service_puis_nom_d_utilisateur(): void
    {
        $alpha = $this->creerService('Alpha');
        $beta = $this->creerService('Beta');

        // Insertion dans le désordre pour prouver le tri (service.nom ASC, u.nom ASC).
        $betaDurand = $this->creerUtilisateur('Durand', RoleUtilisateur::PERSONNEL, $beta);
        $alphaZoe = $this->creerUtilisateur('Zoe', RoleUtilisateur::PERSONNEL, $alpha);
        $alphaAlbert = $this->creerUtilisateur('Albert', RoleUtilisateur::PERSONNEL, $alpha);
        foreach ([$betaDurand, $alphaZoe, $alphaAlbert] as $collegue) {
            $debut = $this->maintenant->modify('+1 day');
            $this->creerCreneau($collegue, $debut, $debut->modify('+1 hour'));
        }
        $this->entityManager->flush();

        $dtos = $this->collegueService->getCollegues($this->current, null, false);

        $noms = array_map(static fn (CollegueDTO $dto): string => $dto->utilisateur->getNom(), $dtos);
        self::assertSame(['Albert', 'Zoe', 'Durand'], $noms);
    }

    public function test_utilisateur_courant_est_exclu_de_sa_propre_liste(): void
    {
        // Le courant possède lui aussi un créneau futur actif : il SERAIT visible s'il
        // n'était pas exclu par findOtherPersonnel (u.id != current).
        $debut = $this->maintenant->modify('+1 day');
        $this->creerCreneau($this->current, $debut, $debut->modify('+1 hour'));

        $collegue = $this->creerUtilisateur('Autre', RoleUtilisateur::PERSONNEL, null);
        $this->creerCreneau($collegue, $debut, $debut->modify('+1 hour'));
        $this->entityManager->flush();

        $dtos = $this->collegueService->getCollegues($this->current, null, false);

        $emails = array_map(static fn (CollegueDTO $dto): string => $dto->utilisateur->getEmail(), $dtos);
        self::assertSame([$collegue->getEmail()], $emails);
        self::assertNotContains($this->current->getEmail(), $emails);
    }

    private function creerUtilisateur(string $nom, RoleUtilisateur $role, ?Service $service): Utilisateur
    {
        $utilisateur = (new Utilisateur())
            ->setEmail(sprintf('dt10-%d-%s@collegue-test.local', ++$this->compteur, uniqid()))
            ->setNom($nom)
            ->setPrenom('Prenom')
            ->setRole($role)
            ->setEstActif(true)
            ->setService($service);
        $utilisateur->setMotDePasseHash('hash-test');

        $this->entityManager->persist($utilisateur);

        return $utilisateur;
    }

    private function creerService(string $nom): Service
    {
        $service = (new Service())->setNom($nom)->setEstActif(true);
        $this->entityManager->persist($service);

        return $service;
    }

    private function creerCreneau(Utilisateur $proprietaire, \DateTimeImmutable $debut, \DateTimeImmutable $fin): Creneau
    {
        $creneau = (new Creneau())
            ->setUtilisateur($proprietaire)
            ->setTypeRdv($this->typeRdv)
            ->setDateDebut($debut)
            ->setDateFin($fin)
            ->setEstActif(true);

        $this->entityManager->persist($creneau);

        return $creneau;
    }

    private function reserver(Creneau $creneau): Reservation
    {
        $reservation = (new Reservation())
            ->setCreneau($creneau)
            ->setUtilisateur($this->auditeur)
            ->setStatut(StatutReservation::ACTIVE);

        $this->entityManager->persist($reservation);

        return $reservation;
    }
}
