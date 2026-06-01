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
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration des agrégats KPI du tableau de bord Super-admin (US-5.1).
 *
 * Vérifie les VALEURS réelles des trois méthodes de la couche données :
 *  - ReservationRepository::countActivesAVenir
 *  - CreneauRepository::countActifsDansFenetre
 *  - CreneauRepository::countReservesActifsDansFenetre
 *
 * Stratégie « baseline + delta » : les KPIs sont GLOBAUX (aucun filtre par
 * personnel), donc on ne peut pas asserter une valeur absolue tant que la BDD
 * test peut déjà contenir des fixtures. On mesure chaque compteur AVANT
 * insertion (baseline), on insère un jeu de données contrôlé, puis on assère
 * que le DELTA (après − avant) vaut exactement l'attendu. Robuste que la BDD
 * test soit vide ou peuplée.
 *
 * Autonome et restaurable : tout est créé en transaction, rollback en tearDown
 * (même pattern que CreneauRepositoryQueriesTest).
 *
 * Jeu de données (fenêtre d'occupation = [maintenant − 30 j, maintenant], bornée
 * sur dateDebut ; « à venir » = dateFin > maintenant) :
 *
 *   clé  dateDebut  estActif  réservation        actives  offre  réservés
 *   A     +5 j      true      ACTIVE               oui      -      -
 *   B     +6 j      false     ACTIVE               -        -      -
 *   C     +7 j      true      —                    -        -      -
 *   D     −3 j      true      ACTIVE               -        oui    oui
 *   E    −10 j      true      —                    -        oui    -
 *   F    −20 j      true      ANNULEE              -        oui    -
 *   G    −15 j      false     ACTIVE               -        -      -
 *   H    −40 j      true      ACTIVE               -        -      -
 *   I     −5 j      true      ACTIVE + ANNULEE     -        oui    oui (×1)
 *
 *   Deltas attendus : actives = 1 (A) ; offre = 4 (D,E,F,I) ; réservés = 2 (D,I).
 */
final class DashboardKpiQueriesTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ReservationRepository $reservationRepository;
    private CreneauRepository $creneauRepository;

    private \DateTimeImmutable $maintenant;
    private \DateTimeImmutable $debutFenetre;

    private int $baselineActivesAVenir;
    private int $baselineOffre;
    private int $baselineReserves;

    private Utilisateur $personnel;
    private Utilisateur $auditeur;
    private TypeRdv $typeRdv;

    protected function setUp(): void
    {
        self::bootKernel(['environment' => 'test']);
        $container = static::getContainer();

        $this->entityManager         = $container->get(EntityManagerInterface::class);
        $this->reservationRepository = $container->get(ReservationRepository::class);
        $this->creneauRepository     = $container->get(CreneauRepository::class);

        $this->maintenant   = new \DateTimeImmutable();
        $this->debutFenetre = $this->maintenant->modify('-30 days');

        $this->entityManager->beginTransaction();

        // Baselines AVANT toute insertion : état pré-existant (fixtures éventuelles).
        $this->baselineActivesAVenir = $this->reservationRepository->countActivesAVenir($this->maintenant);
        $this->baselineOffre         = $this->creneauRepository->countActifsDansFenetre($this->debutFenetre, $this->maintenant);
        $this->baselineReserves      = $this->creneauRepository->countReservesActifsDansFenetre($this->debutFenetre, $this->maintenant);

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

    public function test_countActivesAVenir_ne_compte_que_les_reservations_actives_futures_sur_creneau_actif(): void
    {
        $delta = $this->reservationRepository->countActivesAVenir($this->maintenant) - $this->baselineActivesAVenir;

        // Seul A : futur + ACTIVE + créneau actif.
        // Exclus : B (créneau inactif), C (pas de résa), D/I (passés), F (ANNULEE).
        self::assertSame(1, $delta);
    }

    public function test_countActifsDansFenetre_compte_l_offre_active_de_la_fenetre(): void
    {
        $delta = $this->creneauRepository->countActifsDansFenetre($this->debutFenetre, $this->maintenant) - $this->baselineOffre;

        // D, E, F, I : créneaux actifs dont le début tombe dans [−30 j, maintenant].
        // Exclus : A/B/C (futurs), G (inactif), H (avant la fenêtre).
        self::assertSame(4, $delta);
    }

    public function test_countReservesActifsDansFenetre_ne_compte_que_les_creneaux_avec_resa_active(): void
    {
        $delta = $this->creneauRepository->countReservesActifsDansFenetre($this->debutFenetre, $this->maintenant) - $this->baselineReserves;

        // D (ACTIVE) et I (ACTIVE + ANNULEE → compté une seule fois via EXISTS).
        // Exclus : E (aucune résa), F (ANNULEE seule), G (inactif), H (hors fenêtre).
        self::assertSame(2, $delta);
    }

    public function test_un_creneau_a_double_reservation_n_est_compte_qu_une_fois(): void
    {
        // Garde-fou anti fan-out OneToMany (DT-1) : le créneau I porte 2 réservations
        // (1 ACTIVE + 1 ANNULEE). Le numérateur ne doit pas le compter deux fois.
        // Démontré ici : offre (4) et réservés (2) restent des comptes de créneaux
        // distincts, jamais de lignes de réservation.
        $offre    = $this->creneauRepository->countActifsDansFenetre($this->debutFenetre, $this->maintenant) - $this->baselineOffre;
        $reserves = $this->creneauRepository->countReservesActifsDansFenetre($this->debutFenetre, $this->maintenant) - $this->baselineReserves;

        self::assertLessThanOrEqual($offre, $reserves, 'Le numérateur ne peut excéder le dénominateur.');
        self::assertSame(2, $reserves);
    }

    // ---------------------------------------------------------------------
    // Préparation du jeu de données
    // ---------------------------------------------------------------------

    private function preparerJeuDeDonnees(): void
    {
        $this->typeRdv   = $this->creerTypeRdv();
        $this->personnel = $this->creerUtilisateur(RoleUtilisateur::PERSONNEL, $this->creerService());
        $this->auditeur  = $this->creerUtilisateur(RoleUtilisateur::AUDITEUR, null);

        $creneauA = $this->creerCreneau(5, true);    // futur, actif
        $creneauB = $this->creerCreneau(6, false);   // futur, inactif
        $creneauC = $this->creerCreneau(7, true);    // futur, actif, non réservé
        $creneauD = $this->creerCreneau(-3, true);   // fenêtre, actif
        $creneauE = $this->creerCreneau(-10, true);  // fenêtre, actif, non réservé
        $creneauF = $this->creerCreneau(-20, true);  // fenêtre, actif
        $creneauG = $this->creerCreneau(-15, false); // fenêtre, inactif
        $creneauH = $this->creerCreneau(-40, true);  // hors fenêtre (avant)
        $creneauI = $this->creerCreneau(-5, true);   // fenêtre, actif, double résa

        $this->creerReservation($creneauA, StatutReservation::ACTIVE);
        $this->creerReservation($creneauB, StatutReservation::ACTIVE);
        $this->creerReservation($creneauD, StatutReservation::ACTIVE);
        $this->creerReservation($creneauF, StatutReservation::ANNULEE);
        $this->creerReservation($creneauG, StatutReservation::ACTIVE);
        $this->creerReservation($creneauH, StatutReservation::ACTIVE);
        $this->creerReservation($creneauI, StatutReservation::ACTIVE);
        $this->creerReservation($creneauI, StatutReservation::ANNULEE);
    }

    private function creerCreneau(int $offsetJoursDebut, bool $estActif): Creneau
    {
        $dateDebut = $this->maintenant->modify(sprintf('%+d days', $offsetJoursDebut));

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
            ->setNom('Service KPI Test ' . uniqid())
            ->setEstActif(true);
        $this->entityManager->persist($service);

        return $service;
    }

    private function creerUtilisateur(RoleUtilisateur $role, ?Service $service): Utilisateur
    {
        $utilisateur = (new Utilisateur())
            ->setEmail('kpi-' . uniqid() . '@test.local')
            ->setPrenom('Kpi')
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
            ->setCode('KPI' . strtoupper(substr(uniqid(), -8)))
            ->setLibelle('Type KPI Test')
            ->setCouleurHex('#123456')
            ->setEstActif(true);
        $this->entityManager->persist($typeRdv);

        return $typeRdv;
    }
}
