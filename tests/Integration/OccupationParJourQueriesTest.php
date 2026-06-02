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
 * Test d'intégration de l'agrégat GROUP BY par jour (US-5.2) :
 * CreneauRepository::statistiquesOccupationParJour.
 *
 * Stratégie « baseline + delta PAR JOUR » : l'agrégat est global, donc on mesure
 * l'offre/les réservés du jour cible AVANT insertion, on insère un jeu contrôlé
 * daté de ce jour, et on assère le DELTA. Robuste que la BDD test soit vide ou
 * peuplée de fixtures. Transaction + rollback (pattern CreneauRepositoryQueriesTest).
 *
 * Jeu de données pour le jour cible (maintenant − 3 j) :
 *   C1 actif, réservation ACTIVE            → offre +1, réservés +1
 *   C2 actif, aucune réservation            → offre +1
 *   C3 actif, réservation ANNULEE seule     → offre +1 (réservés 0)
 *   C4 actif, ACTIVE + ANNULEE (re-résa)    → offre +1, réservés +1 (compté une fois)
 *   C5 INACTIF, réservation ACTIVE          → exclu (offre 0, réservés 0)
 *   C6 actif réservé, daté hors fenêtre     → jour absent de la map
 *   ⇒ delta jour cible : offre = 4, réservés = 2.
 */
final class OccupationParJourQueriesTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CreneauRepository $creneauRepository;

    private \DateTimeImmutable $maintenant;
    private \DateTimeImmutable $fenetreDebut;
    private \DateTimeImmutable $jourCible;
    private string $cleJourCible;
    private string $cleJourHorsFenetre;

    private int $baselineOffre;
    private int $baselineReserves;

    private Utilisateur $personnel;
    private Utilisateur $auditeur;
    private TypeRdv $typeRdv;

    protected function setUp(): void
    {
        self::bootKernel(['environment' => 'test']);
        $container = static::getContainer();

        $this->entityManager     = $container->get(EntityManagerInterface::class);
        $this->creneauRepository = $container->get(CreneauRepository::class);

        $this->maintenant         = new \DateTimeImmutable();
        $this->fenetreDebut       = $this->maintenant->modify('-30 days')->setTime(0, 0);
        $this->jourCible          = $this->maintenant->modify('-3 days');
        $this->cleJourCible       = $this->jourCible->format('Y-m-d');
        $this->cleJourHorsFenetre = $this->maintenant->modify('-40 days')->format('Y-m-d');

        $this->entityManager->beginTransaction();

        $statsAvant            = $this->statistiques();
        $this->baselineOffre   = $statsAvant[$this->cleJourCible]['offre'] ?? 0;
        $this->baselineReserves = $statsAvant[$this->cleJourCible]['reserves'] ?? 0;

        $this->preparerJeuDeDonnees();
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

    public function test_offre_du_jour_cible_compte_les_creneaux_actifs(): void
    {
        $stats = $this->statistiques();
        $delta = ($stats[$this->cleJourCible]['offre'] ?? 0) - $this->baselineOffre;

        // C1, C2, C3, C4 (C5 inactif exclu, C6 hors fenêtre).
        self::assertSame(4, $delta);
    }

    public function test_reserves_du_jour_cible_ne_compte_que_les_actives_une_seule_fois(): void
    {
        $stats = $this->statistiques();
        $delta = ($stats[$this->cleJourCible]['reserves'] ?? 0) - $this->baselineReserves;

        // C1 (ACTIVE) et C4 (ACTIVE+ANNULEE compté une fois) ; C3 ANNULEE non comptée.
        self::assertSame(2, $delta);
    }

    public function test_un_jour_hors_fenetre_est_absent_de_la_serie(): void
    {
        $stats = $this->statistiques();

        // La borne BETWEEN exclut tout jour < fenetreDebut, quel que soit l'existant.
        self::assertArrayNotHasKey($this->cleJourHorsFenetre, $stats);
    }

    /**
     * @return array<string, array{offre: int, reserves: int}>
     */
    private function statistiques(): array
    {
        return $this->creneauRepository->statistiquesOccupationParJour($this->fenetreDebut, $this->maintenant);
    }

    // ---------------------------------------------------------------------
    // Préparation du jeu de données
    // ---------------------------------------------------------------------

    private function preparerJeuDeDonnees(): void
    {
        $this->typeRdv   = $this->creerTypeRdv();
        $this->personnel = $this->creerUtilisateur(RoleUtilisateur::PERSONNEL, $this->creerService());
        $this->auditeur  = $this->creerUtilisateur(RoleUtilisateur::AUDITEUR, null);

        $c1 = $this->creerCreneauLeJourCible(9, true);
        $c2 = $this->creerCreneauLeJourCible(10, true);
        $c3 = $this->creerCreneauLeJourCible(11, true);
        $c4 = $this->creerCreneauLeJourCible(13, true);
        $c5 = $this->creerCreneauLeJourCible(14, false);
        $c6 = $this->creerCreneau($this->maintenant->modify('-40 days')->setTime(9, 0), true);

        $this->creerReservation($c1, StatutReservation::ACTIVE);
        $this->creerReservation($c3, StatutReservation::ANNULEE);
        $this->creerReservation($c4, StatutReservation::ACTIVE);
        $this->creerReservation($c4, StatutReservation::ANNULEE);
        $this->creerReservation($c5, StatutReservation::ACTIVE);
        $this->creerReservation($c6, StatutReservation::ACTIVE);
    }

    private function creerCreneauLeJourCible(int $heure, bool $estActif): Creneau
    {
        return $this->creerCreneau($this->jourCible->setTime($heure, 0), $estActif);
    }

    private function creerCreneau(\DateTimeImmutable $dateDebut, bool $estActif): Creneau
    {
        $creneau = (new Creneau())
            ->setUtilisateur($this->personnel)
            ->setTypeRdv($this->typeRdv)
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
                ->setDateAnnulation($this->maintenant)
                ->setMotifAnnulation('Annulation de test');
        }

        $this->entityManager->persist($reservation);

        return $reservation;
    }

    private function creerService(): Service
    {
        $service = (new Service())
            ->setNom('Service Occupation Test ' . uniqid())
            ->setEstActif(true);
        $this->entityManager->persist($service);

        return $service;
    }

    private function creerUtilisateur(RoleUtilisateur $role, ?Service $service): Utilisateur
    {
        $utilisateur = (new Utilisateur())
            ->setEmail('occ-' . uniqid() . '@test.local')
            ->setPrenom('Occ')
            ->setNom('Test')
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
            ->setCode('OCC' . strtoupper(substr(uniqid(), -8)))
            ->setLibelle('Type Occupation Test')
            ->setCouleurHex('#123456')
            ->setEstActif(true);
        $this->entityManager->persist($typeRdv);

        return $typeRdv;
    }
}
