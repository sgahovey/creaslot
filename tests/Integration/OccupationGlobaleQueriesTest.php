<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Creneau;
use App\Entity\Reservation;
use App\Entity\Service;
use App\Entity\TypeRdv;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Enum\StatutReservation;
use App\Repository\CreneauRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration des requêtes de la vue globale occupé/libre (US-5.7) :
 * CreneauRepository::findDansPlageGlobale et ::findIdsCreneauxOccupesDansPlage.
 *
 * Stratégie : on insère un jeu contrôlé en transaction et on assère par
 * APPARTENANCE des ids insérés au résultat (robuste que la BDD test soit vide
 * ou peuplée de fixtures). Transaction + rollback (pattern OccupationParJourQueriesTest).
 *
 * Jeu de données (fenêtre = maintenant − 1 j → maintenant + 10 j) :
 *   c1  pA / service sA / type tX / actif / +1 j / réservation ACTIVE
 *   c2  pB / service sB / type tY / actif / +2 j / réservation ANNULEE seule
 *   c3  pA / service sA / type tX / INACTIF / +3 j
 *   c4  pA / service sA / type tX / actif / +40 j (HORS fenêtre) / ACTIVE
 *   c5  pA / service sA / type tX / actif / +4 j / ACTIVE + ANNULEE (re-résa)
 *   c7  pB / service sB / type tY / actif / +5 j / réservation ACTIVE
 */
final class OccupationGlobaleQueriesTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CreneauRepository $creneauRepository;

    private \DateTimeImmutable $debutFenetre;
    private \DateTimeImmutable $finFenetre;

    private Service $serviceA;
    private Service $serviceB;
    private TypeRdv $typeX;
    private TypeRdv $typeY;
    private Utilisateur $personnelA;
    private Utilisateur $personnelB;
    private Utilisateur $auditeur;

    private Creneau $c1;
    private Creneau $c2;
    private Creneau $c3;
    private Creneau $c4;
    private Creneau $c5;
    private Creneau $c7;

    protected function setUp(): void
    {
        self::bootKernel(['environment' => 'test']);
        $container = static::getContainer();

        $this->entityManager     = $container->get(EntityManagerInterface::class);
        $this->creneauRepository = $container->get(CreneauRepository::class);

        $maintenant         = new \DateTimeImmutable();
        $this->debutFenetre = $maintenant->modify('-1 day')->setTime(0, 0);
        $this->finFenetre   = $maintenant->modify('+10 days')->setTime(23, 59);

        $this->entityManager->beginTransaction();

        $this->preparerJeuDeDonnees($maintenant);
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

    // ---------------------------------------------------------------------
    // findDansPlageGlobale
    // ---------------------------------------------------------------------

    public function test_renvoie_les_creneaux_de_plusieurs_personnels(): void
    {
        $ids = $this->idsCreneaux($this->creneauRepository->findDansPlageGlobale(
            $this->debutFenetre,
            $this->finFenetre,
            null,
            null,
        ));

        // Vue VRAIMENT globale : créneaux de pA (c1) et de pB (c2/c7) tous présents.
        self::assertContains($this->c1->getId(), $ids);
        self::assertContains($this->c2->getId(), $ids);
        self::assertContains($this->c5->getId(), $ids);
        self::assertContains($this->c7->getId(), $ids);
    }

    public function test_exclut_les_creneaux_inactifs(): void
    {
        $ids = $this->idsCreneaux($this->creneauRepository->findDansPlageGlobale(
            $this->debutFenetre,
            $this->finFenetre,
            null,
            null,
        ));

        self::assertNotContains($this->c3->getId(), $ids);
    }

    public function test_exclut_les_creneaux_hors_fenetre(): void
    {
        $ids = $this->idsCreneaux($this->creneauRepository->findDansPlageGlobale(
            $this->debutFenetre,
            $this->finFenetre,
            null,
            null,
        ));

        self::assertNotContains($this->c4->getId(), $ids);
    }

    public function test_filtre_par_service(): void
    {
        $ids = $this->idsCreneaux($this->creneauRepository->findDansPlageGlobale(
            $this->debutFenetre,
            $this->finFenetre,
            $this->serviceA->getId(),
            null,
        ));

        self::assertContains($this->c1->getId(), $ids);
        self::assertContains($this->c5->getId(), $ids);
        self::assertNotContains($this->c2->getId(), $ids);
        self::assertNotContains($this->c7->getId(), $ids);
    }

    public function test_filtre_par_type(): void
    {
        $ids = $this->idsCreneaux($this->creneauRepository->findDansPlageGlobale(
            $this->debutFenetre,
            $this->finFenetre,
            null,
            $this->typeY->getId(),
        ));

        self::assertContains($this->c2->getId(), $ids);
        self::assertContains($this->c7->getId(), $ids);
        self::assertNotContains($this->c1->getId(), $ids);
        self::assertNotContains($this->c5->getId(), $ids);
    }

    public function test_filtre_combine_service_et_type(): void
    {
        $ids = $this->idsCreneaux($this->creneauRepository->findDansPlageGlobale(
            $this->debutFenetre,
            $this->finFenetre,
            $this->serviceA->getId(),
            $this->typeX->getId(),
        ));

        self::assertContains($this->c1->getId(), $ids);
        self::assertContains($this->c5->getId(), $ids);
        self::assertNotContains($this->c2->getId(), $ids);
        self::assertNotContains($this->c7->getId(), $ids);
    }

    // ---------------------------------------------------------------------
    // findIdsCreneauxOccupesDansPlage
    // ---------------------------------------------------------------------

    public function test_ids_occupes_ne_contiennent_que_les_reservations_actives(): void
    {
        $ids = $this->creneauRepository->findIdsCreneauxOccupesDansPlage(
            $this->debutFenetre,
            $this->finFenetre,
            null,
            null,
        );

        self::assertContains($this->c1->getId(), $ids);  // ACTIVE
        self::assertContains($this->c7->getId(), $ids);  // ACTIVE
        self::assertNotContains($this->c2->getId(), $ids); // ANNULEE seule
    }

    public function test_ids_occupes_excluent_un_creneau_hors_fenetre(): void
    {
        $ids = $this->creneauRepository->findIdsCreneauxOccupesDansPlage(
            $this->debutFenetre,
            $this->finFenetre,
            null,
            null,
        );

        // c4 a une réservation ACTIVE mais son début est hors fenêtre.
        self::assertNotContains($this->c4->getId(), $ids);
    }

    public function test_un_creneau_active_et_annulee_n_est_compte_qu_une_fois(): void
    {
        $ids = $this->creneauRepository->findIdsCreneauxOccupesDansPlage(
            $this->debutFenetre,
            $this->finFenetre,
            null,
            null,
        );

        $occurrences = array_filter($ids, fn (int $id): bool => $id === $this->c5->getId());

        self::assertCount(1, $occurrences);
    }

    public function test_ids_occupes_respectent_les_filtres(): void
    {
        $idsServiceA = $this->creneauRepository->findIdsCreneauxOccupesDansPlage(
            $this->debutFenetre,
            $this->finFenetre,
            $this->serviceA->getId(),
            null,
        );

        self::assertContains($this->c1->getId(), $idsServiceA);
        self::assertContains($this->c5->getId(), $idsServiceA);
        self::assertNotContains($this->c7->getId(), $idsServiceA); // pB / service sB

        $idsTypeY = $this->creneauRepository->findIdsCreneauxOccupesDansPlage(
            $this->debutFenetre,
            $this->finFenetre,
            null,
            $this->typeY->getId(),
        );

        self::assertContains($this->c7->getId(), $idsTypeY);
        self::assertNotContains($this->c1->getId(), $idsTypeY); // type tX
        self::assertNotContains($this->c5->getId(), $idsTypeY);
    }

    // ---------------------------------------------------------------------
    // Préparation du jeu de données
    // ---------------------------------------------------------------------

    private function preparerJeuDeDonnees(\DateTimeImmutable $maintenant): void
    {
        $this->serviceA = $this->creerService();
        $this->serviceB = $this->creerService();
        $this->typeX    = $this->creerTypeRdv();
        $this->typeY    = $this->creerTypeRdv();

        $this->personnelA = $this->creerUtilisateur(RoleUtilisateur::PERSONNEL, $this->serviceA);
        $this->personnelB = $this->creerUtilisateur(RoleUtilisateur::PERSONNEL, $this->serviceB);
        $this->auditeur   = $this->creerUtilisateur(RoleUtilisateur::AUDITEUR, null);

        $this->c1 = $this->creerCreneau($this->personnelA, $this->typeX, $maintenant->modify('+1 day'), true);
        $this->c2 = $this->creerCreneau($this->personnelB, $this->typeY, $maintenant->modify('+2 days'), true);
        $this->c3 = $this->creerCreneau($this->personnelA, $this->typeX, $maintenant->modify('+3 days'), false);
        $this->c4 = $this->creerCreneau($this->personnelA, $this->typeX, $maintenant->modify('+40 days'), true);
        $this->c5 = $this->creerCreneau($this->personnelA, $this->typeX, $maintenant->modify('+4 days'), true);
        $this->c7 = $this->creerCreneau($this->personnelB, $this->typeY, $maintenant->modify('+5 days'), true);

        $this->creerReservation($this->c1, StatutReservation::ACTIVE);
        $this->creerReservation($this->c2, StatutReservation::ANNULEE);
        $this->creerReservation($this->c4, StatutReservation::ACTIVE);
        $this->creerReservation($this->c5, StatutReservation::ACTIVE);
        $this->creerReservation($this->c5, StatutReservation::ANNULEE);
        $this->creerReservation($this->c7, StatutReservation::ACTIVE);
    }

    /**
     * @param Creneau[] $creneaux
     *
     * @return list<int>
     */
    private function idsCreneaux(array $creneaux): array
    {
        return array_map(static fn (Creneau $c): int => (int) $c->getId(), $creneaux);
    }

    private function creerCreneau(
        Utilisateur $personnel,
        TypeRdv $typeRdv,
        \DateTimeImmutable $dateDebut,
        bool $estActif,
    ): Creneau {
        $creneau = (new Creneau())
            ->setUtilisateur($personnel)
            ->setTypeRdv($typeRdv)
            ->setDateDebut($dateDebut)
            ->setDateFin($dateDebut->modify('+1 hour'))
            ->setEstActif($estActif);

        $this->entityManager->persist($creneau);

        return $creneau;
    }

    private function creerReservation(Creneau $creneau, StatutReservation $statut): Reservation
    {
        $reservation = (new Reservation())
            ->setCreneau($creneau)
            ->setUtilisateur($this->auditeur)
            ->setStatut($statut);

        if ($statut === StatutReservation::ANNULEE) {
            $reservation
                ->setDateAnnulation(new \DateTimeImmutable())
                ->setMotifAnnulation('Annulation de test');
        }

        $this->entityManager->persist($reservation);

        return $reservation;
    }

    private function creerService(): Service
    {
        $service = (new Service())
            ->setNom('Service Occupation Globale ' . uniqid())
            ->setEstActif(true);
        $this->entityManager->persist($service);

        return $service;
    }

    private function creerUtilisateur(RoleUtilisateur $role, ?Service $service): Utilisateur
    {
        $utilisateur = (new Utilisateur())
            ->setEmail('occ-glob-' . uniqid() . '@test.local')
            ->setPrenom('Occ')
            ->setNom('Globale')
            ->setRole($role)
            ->setEstActif(true)
            ->setService($service)
            ->setMotDePasseHash('placeholder-not-real');
        $this->entityManager->persist($utilisateur);

        return $utilisateur;
    }

    private function creerTypeRdv(): TypeRdv
    {
        $typeRdv = (new TypeRdv())
            ->setCode('OGL' . strtoupper(substr(uniqid(), -8)))
            ->setLibelle('Type Occupation Globale')
            ->setCouleurHex('#123456')
            ->setEstActif(true);
        $this->entityManager->persist($typeRdv);

        return $typeRdv;
    }
}
