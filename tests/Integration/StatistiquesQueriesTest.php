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
 * Tests d'intégration des agrégats GROUP BY par service / par type (US-5.8) :
 * CreneauRepository::statistiquesParService et ::statistiquesParType.
 *
 * Les services et types du jeu d'essai sont créés frais (noms/codes uniques),
 * donc leurs clés n'existent pas dans l'existant : on peut asséner des valeurs
 * absolues. Seul le bucket « Sans service » (clé-sentinelle 0) peut pré-exister
 * en base, donc on l'évalue en baseline + delta. Transaction + rollback (pattern
 * OccupationParJourQueriesTest).
 *
 * La fenêtre est PROSPECTIVE (de maintenant à maintenant + 30 j) : on mesure
 * l'occupation des créneaux à venir, pas l'historique. Jeu de données daté du jour
 * cible (maintenant + 3 j, dans la fenêtre 30 j) ; le cas hors-fenêtre est au-delà :
 *   C1  personnelA / serviceA      / typeX  / ACTIVE              → offre +1, réservés +1
 *   C2  personnelA / serviceA      / typeX  / ACTIVE + ANNULEE    → offre +1, réservés +1 (1 fois)
 *   C3  personnelA / serviceA      / typeY  / ANNULEE seule       → offre +1, réservés 0
 *   C4  personnelB / serviceB (off) / typeX / ACTIVE              → offre +1, réservés +1
 *   C5  personnelSansService / null / typeY / ACTIVE              → offre +1, réservés +1
 *   C6  personnelA / serviceA      / typeX  / ACTIVE, à +40 j     → hors fenêtre, exclu
 *   C7  personnelA / serviceA      / typeX  / ACTIVE, INACTIF     → exclu
 *
 *   serviceA → offre 3, réservés 2 ; serviceB → offre 1, réservés 1 ;
 *   sans service → delta offre 1, réservés 1.
 *   typeX → offre 3, réservés 3 ; typeY → offre 2, réservés 1.
 */
final class StatistiquesQueriesTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CreneauRepository $creneauRepository;

    private \DateTimeImmutable $maintenant;
    private \DateTimeImmutable $fenetreFin;
    private \DateTimeImmutable $jourCible;

    private int $baselineSansServiceOffre;
    private int $baselineSansServiceReserves;

    private Service $serviceA;
    private Service $serviceB;
    private TypeRdv $typeX;
    private TypeRdv $typeY;
    private Utilisateur $auditeur;

    private const string COULEUR_TYPE_X = '#AB12CD';

    protected function setUp(): void
    {
        self::bootKernel(['environment' => 'test']);
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->creneauRepository = $container->get(CreneauRepository::class);

        $this->maintenant = new \DateTimeImmutable();
        $this->fenetreFin = $this->maintenant->modify('+30 days')->setTime(23, 59);
        $this->jourCible = $this->maintenant->modify('+3 days');

        $this->entityManager->beginTransaction();

        $bucketSansServiceAvant = $this->statistiquesParService()[0] ?? ['offre' => 0, 'reserves' => 0];
        $this->baselineSansServiceOffre = $bucketSansServiceAvant['offre'];
        $this->baselineSansServiceReserves = $bucketSansServiceAvant['reserves'];

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

    // ---------------------------------------------------------------------
    // Axe service
    // ---------------------------------------------------------------------

    public function test_par_service_compte_offre_et_reserves_par_service(): void
    {
        $stats = $this->statistiquesParService();

        self::assertSame(3, $stats[$this->idDe($this->serviceA)]['offre']);
        self::assertSame(2, $stats[$this->idDe($this->serviceA)]['reserves']);
        self::assertSame($this->serviceA->getNom(), $stats[$this->idDe($this->serviceA)]['nom']);
        self::assertSame($this->idDe($this->serviceA), $stats[$this->idDe($this->serviceA)]['serviceId']);
    }

    public function test_par_service_regroupe_les_personnels_sans_service_sous_la_cle_sentinelle(): void
    {
        $stats = $this->statistiquesParService();
        $bucket = $stats[0];

        self::assertNull($bucket['serviceId']);
        self::assertNull($bucket['nom']);
        self::assertSame(1, $bucket['offre'] - $this->baselineSansServiceOffre);
        self::assertSame(1, $bucket['reserves'] - $this->baselineSansServiceReserves);
    }

    public function test_par_service_ne_compte_les_reserves_actives_qu_une_fois(): void
    {
        // serviceA : C1 (ACTIVE) + C2 (ACTIVE+ANNULEE compté 1 fois) = 2 ;
        // C3 (ANNULEE seule) non comptée bien que le créneau soit offert (offre 3).
        $stats = $this->statistiquesParService();

        self::assertSame(3, $stats[$this->idDe($this->serviceA)]['offre']);
        self::assertSame(2, $stats[$this->idDe($this->serviceA)]['reserves']);
    }

    public function test_par_service_inclut_un_service_desactive_ayant_des_creneaux(): void
    {
        $stats = $this->statistiquesParService();

        self::assertArrayHasKey($this->serviceB->getId(), $stats);
        self::assertSame(1, $stats[$this->serviceB->getId()]['offre']);
        self::assertSame(1, $stats[$this->serviceB->getId()]['reserves']);
    }

    public function test_par_service_exclut_les_creneaux_hors_fenetre_et_inactifs(): void
    {
        // C6 (hors 30 j) et C7 (inactif), tous deux sur serviceA, ne gonflent pas
        // l'offre : si l'un était compté, serviceA aurait offre > 3.
        $stats = $this->statistiquesParService();

        self::assertSame(3, $stats[$this->idDe($this->serviceA)]['offre']);
    }

    // ---------------------------------------------------------------------
    // Axe type
    // ---------------------------------------------------------------------

    public function test_par_type_compte_offre_reserves_et_couleur(): void
    {
        $stats = $this->statistiquesParType();

        self::assertSame(3, $stats[$this->idDe($this->typeX)]['offre']);
        self::assertSame(3, $stats[$this->idDe($this->typeX)]['reserves']);
        self::assertSame(self::COULEUR_TYPE_X, $stats[$this->idDe($this->typeX)]['couleurHex']);
        self::assertSame($this->typeX->getLibelle(), $stats[$this->idDe($this->typeX)]['libelle']);
    }

    public function test_par_type_inclut_un_type_desactive_et_ne_compte_que_les_actives(): void
    {
        // typeY (désactivé) : C3 (ANNULEE seule) + C5 (ACTIVE) → offre 2, réservés 1.
        $stats = $this->statistiquesParType();

        self::assertArrayHasKey($this->typeY->getId(), $stats);
        self::assertSame(2, $stats[$this->typeY->getId()]['offre']);
        self::assertSame(1, $stats[$this->typeY->getId()]['reserves']);
    }

    // ---------------------------------------------------------------------
    // Accès agrégats
    // ---------------------------------------------------------------------

    /**
     * @return array<int, array{serviceId: int|null, nom: string|null, offre: int, reserves: int}>
     */
    private function statistiquesParService(): array
    {
        return $this->creneauRepository->statistiquesParService($this->maintenant, $this->fenetreFin);
    }

    /**
     * @return array<int, array{typeId: int, libelle: string, couleurHex: string, offre: int, reserves: int}>
     */
    private function statistiquesParType(): array
    {
        return $this->creneauRepository->statistiquesParType($this->maintenant, $this->fenetreFin);
    }

    /** Identifiant d'une entité persistée, en garantissant qu'il est bien attribué (clé d'agrégat). */
    private function idDe(Service|TypeRdv $entite): int
    {
        $id = $entite->getId();
        self::assertNotNull($id);

        return $id;
    }

    // ---------------------------------------------------------------------
    // Préparation du jeu de données
    // ---------------------------------------------------------------------

    private function preparerJeuDeDonnees(): void
    {
        $this->serviceA = $this->creerService(true);
        $this->serviceB = $this->creerService(false);
        $this->typeX = $this->creerTypeRdv(true, self::COULEUR_TYPE_X);
        $this->typeY = $this->creerTypeRdv(false, '#654321');
        $this->auditeur = $this->creerUtilisateur(RoleUtilisateur::AUDITEUR, null);

        $personnelA = $this->creerUtilisateur(RoleUtilisateur::PERSONNEL, $this->serviceA);
        $personnelB = $this->creerUtilisateur(RoleUtilisateur::PERSONNEL, $this->serviceB);
        $personnelSansService = $this->creerUtilisateur(RoleUtilisateur::PERSONNEL, null);

        $c1 = $this->creerCreneau($personnelA, $this->typeX, $this->jourCible->setTime(9, 0), true);
        $c2 = $this->creerCreneau($personnelA, $this->typeX, $this->jourCible->setTime(10, 0), true);
        $c3 = $this->creerCreneau($personnelA, $this->typeY, $this->jourCible->setTime(11, 0), true);
        $c4 = $this->creerCreneau($personnelB, $this->typeX, $this->jourCible->setTime(13, 0), true);
        $c5 = $this->creerCreneau($personnelSansService, $this->typeY, $this->jourCible->setTime(14, 0), true);
        $c6 = $this->creerCreneau($personnelA, $this->typeX, $this->maintenant->modify('+40 days')->setTime(9, 0), true);
        $c7 = $this->creerCreneau($personnelA, $this->typeX, $this->jourCible->setTime(15, 0), false);

        $this->creerReservation($c1, StatutReservation::ACTIVE);
        $this->creerReservation($c2, StatutReservation::ACTIVE);
        $this->creerReservation($c2, StatutReservation::ANNULEE);
        $this->creerReservation($c3, StatutReservation::ANNULEE);
        $this->creerReservation($c4, StatutReservation::ACTIVE);
        $this->creerReservation($c5, StatutReservation::ACTIVE);
        $this->creerReservation($c6, StatutReservation::ACTIVE);
        $this->creerReservation($c7, StatutReservation::ACTIVE);
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
                ->setDateAnnulation($this->maintenant)
                ->setMotifAnnulation('Annulation de test');
        }

        $this->entityManager->persist($reservation);

        return $reservation;
    }

    private function creerService(bool $estActif): Service
    {
        $service = (new Service())
            ->setNom('Service Stats Test ' . uniqid())
            ->setEstActif($estActif);
        $this->entityManager->persist($service);

        return $service;
    }

    private function creerUtilisateur(RoleUtilisateur $role, ?Service $service): Utilisateur
    {
        $utilisateur = (new Utilisateur())
            ->setEmail('stats-' . uniqid() . '@test.local')
            ->setPrenom('Stats')
            ->setNom('Test')
            ->setRole($role)
            ->setEstActif(true)
            ->setService($service)
            ->setMotDePasseHash('placeholder-not-real');
        $this->entityManager->persist($utilisateur);

        return $utilisateur;
    }

    private function creerTypeRdv(bool $estActif, string $couleurHex): TypeRdv
    {
        $typeRdv = (new TypeRdv())
            ->setCode('STA' . strtoupper(substr(uniqid(), -8)))
            ->setLibelle('Type Stats Test ' . uniqid())
            ->setCouleurHex($couleurHex)
            ->setEstActif($estActif);
        $this->entityManager->persist($typeRdv);

        return $typeRdv;
    }
}
