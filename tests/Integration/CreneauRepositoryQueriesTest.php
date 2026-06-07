<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Creneau;
use App\Entity\Service;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Repository\CreneauRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
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
        // CRITIQUE : query exacte qui a déclenché le HTTP 500 en E2E DT-2.
        foreach (['tous', 'a_venir', 'passes', 'annules'] as $filtre) {
            $paginator = $this->creneauRepository->findByPersonnelWithFilters($this->personnel, $filtre, 1);

            self::assertInstanceOf(Paginator::class, $paginator);
            // count() déclenche la query COUNT, iterator_to_array() la query data :
            // les deux portent les JOIN → le bug DQL serait levé ici si présent.
            self::assertIsInt(count($paginator));
            self::assertIsArray(iterator_to_array($paginator));
        }
    }

    public function test_find_by_personnel_in_date_range_executes_sans_erreur_dql(): void
    {
        $debut = new \DateTimeImmutable('2026-01-01');
        $fin = new \DateTimeImmutable('2027-12-31');

        self::assertIsArray($this->creneauRepository->findByPersonnelInDateRange($this->personnel, $debut, $fin, false));
        self::assertIsArray($this->creneauRepository->findByPersonnelInDateRange($this->personnel, $debut, $fin, true));
    }

    public function test_find_chevauchements_executes_sans_erreur_dql(): void
    {
        $debut = new \DateTimeImmutable('2026-06-01 10:00');
        $fin = new \DateTimeImmutable('2026-06-01 11:00');

        self::assertIsArray($this->creneauRepository->findChevauchements($this->personnel, $debut, $fin));
        self::assertIsArray($this->creneauRepository->findChevauchements($this->personnel, $debut, $fin, 999));
    }

    public function test_find_next_reserved_creneau_executes_sans_erreur_dql(): void
    {
        $result = $this->creneauRepository->findNextReservedCreneau($this->personnel);

        self::assertTrue($result === null || $result instanceof Creneau);
    }

    public function test_find_creneau_en_cours_avec_rdv_executes_sans_erreur_dql(): void
    {
        $result = $this->creneauRepository->findCreneauEnCoursAvecRdv($this->personnel, new \DateTimeImmutable());

        self::assertTrue($result === null || $result instanceof Creneau);
    }

    public function test_find_disponibles_executes_sans_erreur_dql(): void
    {
        $paginator = $this->creneauRepository->findDisponibles(null, null, null, 1);

        self::assertInstanceOf(Paginator::class, $paginator);
        self::assertIsInt(count($paginator));
        self::assertIsArray(iterator_to_array($paginator));
    }

    public function test_existe_creneau_actif_futur_ou_en_cours_executes_sans_erreur_dql(): void
    {
        $result = $this->creneauRepository->existeCreneauActifFuturOuEnCours($this->personnel, new \DateTimeImmutable());

        self::assertIsBool($result);
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
}
