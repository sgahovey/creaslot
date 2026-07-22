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
 * Test d'intégration CreneauRepository : non-régression DQL.
 *
 * Motivation (hotfix DT-1 résiduel détecté en E2E DT-2, 28/05/2026) :
 * Le refacto Entity DT-1 (OneToOne → OneToMany) a renommé l'association
 * `reservation` → `reservations`. Cinq méthodes du Repository référençaient
 * encore `c.reservation` (singulier) en DQL → HTTP 500 « has no association
 * named reservation ». Les tests unitaires existants mockent le Repository et
 * ne sollicitent donc JAMAIS la DQL réelle : la faille de couverture a laissé
 * passer la régression.
 *
 * Ce test exécute CHAQUE méthode publique du Repository qui produit de la DQL,
 * contre la vraie BDD test, pour garantir que la query parse + le mapping est
 * cohérent. Il ne teste PAS la sémantique métier (résultats) — uniquement que
 * la requête s'exécute sans exception Doctrine. Objectif : fermer la faille de
 * couverture qui a permis l'oubli DT-1.
 *
 * Complément DT-39 : `test_find_disponibles_retourne_les_creneaux_disponibles_et_exclut_les_autres`
 * va plus loin que le smoke test et asserte le RÉSULTAT métier de `findDisponibles`
 * (créneau réservé ACTIVE exclu, redevenu disponible après annulation, passé exclu,
 * propriétaire inactif exclu), isolé des fixtures par un filtre `serviceId` dédié.
 *
 * Autonome : crée son propre Personnel en transaction (rollback en tearDown),
 * sans dépendre des fixtures chargées en BDD test.
 *
 * @see CreneauRepository
 */
final class CreneauRepositoryQueriesTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CreneauRepository $creneauRepository;
    private Utilisateur $personnel;

    protected function setUp(): void
    {
        self::bootKernel(['environment' => 'test']);
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->creneauRepository = $container->get(CreneauRepository::class);

        $this->entityManager->beginTransaction();

        $this->personnel = $this->creerPersonnel();
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

    public function test_find_by_personnel_with_filters_executes_sans_erreur_dql(): void
    {
        // Smoke test DQL : la query doit s'exécuter (le bug DT-2 levait un HTTP 500 ici).
        // CRITIQUE : query exacte qui a déclenché le HTTP 500 en E2E DT-2.
        foreach (['tous', 'a_venir', 'passes', 'annules'] as $filtre) {
            $paginator = $this->creneauRepository->findByPersonnelWithFilters($this->personnel, $filtre, 1);

            // assertCount sur le Paginator déclenche la query COUNT, sur le tableau la
            // query data : les deux portent les JOIN → un bug DQL serait levé ici. Le
            // personnel vient d'être créé sans créneau → ensemble vide attendu.
            self::assertCount(0, $paginator);
            self::assertCount(0, iterator_to_array($paginator));
        }
    }

    public function test_find_by_personnel_in_date_range_executes_sans_erreur_dql(): void
    {
        $this->expectNotToPerformAssertions();

        $debut = new \DateTimeImmutable('2026-01-01');
        $fin = new \DateTimeImmutable('2027-12-31');

        $this->creneauRepository->findByPersonnelInDateRange($this->personnel, $debut, $fin, false);
        $this->creneauRepository->findByPersonnelInDateRange($this->personnel, $debut, $fin, true);
    }

    public function test_find_chevauchements_executes_sans_erreur_dql(): void
    {
        $this->expectNotToPerformAssertions();

        $debut = new \DateTimeImmutable('2026-06-01 10:00');
        $fin = new \DateTimeImmutable('2026-06-01 11:00');

        $this->creneauRepository->findChevauchements($this->personnel, $debut, $fin);
        $this->creneauRepository->findChevauchements($this->personnel, $debut, $fin, 999);
    }

    public function test_find_next_reserved_creneau_executes_sans_erreur_dql(): void
    {
        $this->expectNotToPerformAssertions();

        $this->creneauRepository->findNextReservedCreneau($this->personnel);
    }

    public function test_find_creneau_en_cours_avec_rdv_executes_sans_erreur_dql(): void
    {
        $this->expectNotToPerformAssertions();

        $this->creneauRepository->findCreneauEnCoursAvecRdv($this->personnel, new \DateTimeImmutable());
    }

    public function test_find_disponibles_executes_sans_erreur_dql(): void
    {
        $paginator = $this->creneauRepository->findDisponibles(null, null, null, 1);

        // Non scopé au personnel de test → le total dépend des fixtures, on ne fige
        // donc pas de valeur. count() déclenche la query COUNT, iterator_to_array() la
        // query data ; invariant vérifié : la page (limite 1) ne dépasse pas le total.
        self::assertLessThanOrEqual(count($paginator), count(iterator_to_array($paginator)));
    }

    public function test_find_disponibles_retourne_les_creneaux_disponibles_et_exclut_les_autres(): void
    {
        // Isolation totale des fixtures : on filtre par un Service de test dédié, donc
        // seuls les créneaux créés ici (rattachés à ce service) peuvent apparaître.
        $service = $this->creerServiceDedie();
        $personnelActif = $this->creerPersonnelDansService($service, estActif: true);
        $personnelInactif = $this->creerPersonnelDansService($service, estActif: false);
        $auditeur = $this->creerAuditeur();
        $typeRdv = $this->trouverOuCreerTypeRdv();

        $futur = (new \DateTimeImmutable('+1 year'))->setTime(9, 0);
        $passe = (new \DateTimeImmutable('-1 year'))->setTime(9, 0);

        $creneauLibre = $this->creerCreneau($personnelActif, $typeRdv, $futur, $futur->modify('+1 hour'));
        $creneauActive = $this->creerCreneau($personnelActif, $typeRdv, $futur->setTime(10, 0), $futur->setTime(11, 0));
        $creneauAnnulee = $this->creerCreneau($personnelActif, $typeRdv, $futur->setTime(11, 0), $futur->setTime(12, 0));
        $creneauPasse = $this->creerCreneau($personnelActif, $typeRdv, $passe, $passe->modify('+1 hour'));
        $creneauPersoInactif = $this->creerCreneau($personnelInactif, $typeRdv, $futur->setTime(13, 0), $futur->setTime(14, 0));

        $this->creerReservation($auditeur, $creneauActive, StatutReservation::ACTIVE);
        $this->creerReservation($auditeur, $creneauAnnulee, StatutReservation::ANNULEE);

        $this->entityManager->flush();

        $paginator = $this->creneauRepository->findDisponibles(null, (int) $service->getId(), null, 1);

        /** @var list<int> $idsRetournes */
        $idsRetournes = array_map(
            static fn (Creneau $creneau): int => (int) $creneau->getId(),
            iterator_to_array($paginator),
        );

        self::assertCount(2, $paginator, 'Seuls 2 créneaux du service de test sont disponibles.');
        self::assertContains((int) $creneauLibre->getId(), $idsRetournes, 'Le créneau libre doit apparaître.');
        self::assertContains((int) $creneauAnnulee->getId(), $idsRetournes, 'Un créneau dont la réservation est ANNULEE redevient disponible.');
        self::assertNotContains((int) $creneauActive->getId(), $idsRetournes, 'Un créneau réservé ACTIVE est exclu.');
        self::assertNotContains((int) $creneauPasse->getId(), $idsRetournes, 'Un créneau passé est exclu.');
        self::assertNotContains((int) $creneauPersoInactif->getId(), $idsRetournes, "Un créneau d'un personnel inactif est exclu.");
    }

    public function test_existe_creneau_actif_futur_ou_en_cours_executes_sans_erreur_dql(): void
    {
        $this->expectNotToPerformAssertions();

        $this->creneauRepository->existeCreneauActifFuturOuEnCours($this->personnel, new \DateTimeImmutable());
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function creerPersonnel(): Utilisateur
    {
        $service = new Service();
        $service->setNom('Service Test Queries ' . uniqid())->setEstActif(true);
        $this->entityManager->persist($service);

        $personnel = new Utilisateur();
        $personnel->setEmail('queries-personnel-' . uniqid() . '@test.local')
                  ->setPrenom('Marie')
                  ->setNom('TestQueries')
                  ->setRole(RoleUtilisateur::PERSONNEL)
                  ->setEstActif(true)
                  ->setService($service)
                  ->setMotDePasseHash('placeholder-not-real');
        $this->entityManager->persist($personnel);

        return $personnel;
    }

    private function creerServiceDedie(): Service
    {
        $service = new Service();
        $service->setNom('Service Test Dispo ' . uniqid())->setEstActif(true);
        $this->entityManager->persist($service);

        return $service;
    }

    private function creerPersonnelDansService(Service $service, bool $estActif): Utilisateur
    {
        $personnel = new Utilisateur();
        $personnel->setEmail('dispo-personnel-' . uniqid() . '@test.local')
                  ->setPrenom('Perso')
                  ->setNom('TestDispo')
                  ->setRole(RoleUtilisateur::PERSONNEL)
                  ->setEstActif($estActif)
                  ->setService($service)
                  ->setMotDePasseHash('placeholder-not-real');
        $this->entityManager->persist($personnel);

        return $personnel;
    }

    private function creerAuditeur(): Utilisateur
    {
        $auditeur = new Utilisateur();
        $auditeur->setEmail('dispo-auditeur-' . uniqid() . '@test.local')
                 ->setPrenom('Xavier')
                 ->setNom('TestDispo')
                 ->setRole(RoleUtilisateur::AUDITEUR)
                 ->setEstActif(true)
                 ->setMotDePasseHash('placeholder-not-real');
        $this->entityManager->persist($auditeur);

        return $auditeur;
    }

    private function trouverOuCreerTypeRdv(): TypeRdv
    {
        $existant = $this->entityManager->getRepository(TypeRdv::class)->findOneBy([]);
        if ($existant !== null) {
            return $existant;
        }

        $typeRdv = new TypeRdv();
        $typeRdv->setCode('TEST_DISPO_' . substr(uniqid(), -6))
                ->setLibelle('Test Dispo')
                ->setCouleurHex('#1A3E6F')
                ->setEstActif(true);
        $this->entityManager->persist($typeRdv);

        return $typeRdv;
    }

    private function creerCreneau(
        Utilisateur $personnel,
        TypeRdv $typeRdv,
        \DateTimeImmutable $debut,
        \DateTimeImmutable $fin,
    ): Creneau {
        $creneau = (new Creneau())
            ->setUtilisateur($personnel)
            ->setTypeRdv($typeRdv)
            ->setDateDebut($debut)
            ->setDateFin($fin)
            ->setEstActif(true);
        $this->entityManager->persist($creneau);

        return $creneau;
    }

    private function creerReservation(Utilisateur $auditeur, Creneau $creneau, StatutReservation $statut): Reservation
    {
        $reservation = (new Reservation())
            ->setCreneau($creneau)
            ->setUtilisateur($auditeur)
            ->setStatut($statut);
        $this->entityManager->persist($reservation);

        return $reservation;
    }
}
