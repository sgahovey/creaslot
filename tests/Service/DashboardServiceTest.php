<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\OccupationJournaliere;
use App\Repository\CreneauRepository;
use App\Repository\ReservationRepository;
use App\Service\DashboardService;
use PHPUnit\Framework\TestCase;

/**
 * Test unitaire du calcul des KPIs du tableau de bord Super-admin (US-5.1).
 *
 * Les repositories sont remplacés par des stubs (createStub, pattern DT-3 :
 * doublures sans expectations → zéro notice PHPUnit). On isole ainsi la logique
 * métier (taux d'occupation, garde-fou division par zéro, fenêtre temporelle)
 * sans BDD ni horloge réelle à contrôler.
 */
final class DashboardServiceTest extends TestCase
{
    public function test_calcul_nominal(): void
    {
        $kpis = $this->creerServiceAvecCompteurs(activesAVenir: 5, offre: 10, reserves: 4)->calculerKpis();

        self::assertSame(5, $kpis->reservationsActives);
        self::assertSame(40.0, $kpis->tauxOccupation);
        self::assertSame(4, $kpis->creneauxReserves);
        self::assertSame(10, $kpis->creneauxOffre);
    }

    public function test_offre_nulle_donne_un_taux_de_zero_sans_division_par_zero(): void
    {
        $kpis = $this->creerServiceAvecCompteurs(activesAVenir: 7, offre: 0, reserves: 0)->calculerKpis();

        self::assertSame(0.0, $kpis->tauxOccupation);
        self::assertSame(7, $kpis->reservationsActives);
    }

    public function test_taux_arrondi_a_une_decimale(): void
    {
        $kpis = $this->creerServiceAvecCompteurs(activesAVenir: 0, offre: 3, reserves: 1)->calculerKpis();

        self::assertSame(33.3, $kpis->tauxOccupation);
    }

    public function test_occupation_pleine(): void
    {
        $kpis = $this->creerServiceAvecCompteurs(activesAVenir: 2, offre: 6, reserves: 6)->calculerKpis();

        self::assertSame(100.0, $kpis->tauxOccupation);
    }

    public function test_fenetre_du_taux_couvre_exactement_30_jours(): void
    {
        $kpis = $this->creerServiceAvecCompteurs(activesAVenir: 0, offre: 0, reserves: 0)->calculerKpis();

        self::assertSame(30, $kpis->fenetreDebut->diff($kpis->fenetreFin)->days);
    }

    public function test_get_occupation_par_jour_comble_les_jours_manquants_et_reste_chronologique(): void
    {
        $aujourdhui = new \DateTimeImmutable();
        $jourRecent = $aujourdhui->format('Y-m-d');
        $jourMilieu = $aujourdhui->modify('-5 days')->format('Y-m-d');

        // Map creuse : seulement 2 jours sur 30 renseignés.
        $service = $this->creerServiceAvecOccupation([
            $jourMilieu => ['offre' => 8, 'reserves' => 3],
            $jourRecent => ['offre' => 5, 'reserves' => 5],
        ]);

        $serie = $service->getOccupationParJour();

        self::assertCount(30, $serie);
        self::assertContainsOnlyInstancesOf(OccupationJournaliere::class, $serie);

        // Ordre chronologique croissant.
        $jours = array_map(static fn (OccupationJournaliere $o) => $o->jour, $serie);
        $joursTries = $jours;
        sort($joursTries);
        self::assertSame($joursTries, $jours);

        // Bornes : premier jour = aujourd'hui − 29 j, dernier = aujourd'hui.
        self::assertSame($aujourdhui->modify('-29 days')->format('Y-m-d'), $serie[0]->jour);
        self::assertSame($jourRecent, $serie[29]->jour);

        // Les 2 jours présents portent les bonnes valeurs.
        self::assertSame(5, $serie[29]->offre);
        self::assertSame(5, $serie[29]->reserves);
        $milieu = $this->trouverJour($serie, $jourMilieu);
        self::assertSame(8, $milieu->offre);
        self::assertSame(3, $milieu->reserves);

        // Un jour absent de la map est comblé à 0.
        $vide = $this->trouverJour($serie, $aujourdhui->modify('-10 days')->format('Y-m-d'));
        self::assertSame(0, $vide->offre);
        self::assertSame(0, $vide->reserves);
    }

    private function creerServiceAvecCompteurs(int $activesAVenir, int $offre, int $reserves): DashboardService
    {
        $reservationRepository = $this->createStub(ReservationRepository::class);
        $reservationRepository->method('countActivesAVenir')->willReturn($activesAVenir);

        $creneauRepository = $this->createStub(CreneauRepository::class);
        $creneauRepository->method('countActifsDansFenetre')->willReturn($offre);
        $creneauRepository->method('countReservesActifsDansFenetre')->willReturn($reserves);

        return new DashboardService($reservationRepository, $creneauRepository);
    }

    /**
     * @param array<string, array{offre: int, reserves: int}> $occupationParJour
     */
    private function creerServiceAvecOccupation(array $occupationParJour): DashboardService
    {
        $creneauRepository = $this->createStub(CreneauRepository::class);
        $creneauRepository->method('statistiquesOccupationParJour')->willReturn($occupationParJour);

        return new DashboardService($this->createStub(ReservationRepository::class), $creneauRepository);
    }

    /**
     * @param list<OccupationJournaliere> $serie
     */
    private function trouverJour(array $serie, string $jour): OccupationJournaliere
    {
        foreach ($serie as $occupation) {
            if ($occupation->jour === $jour) {
                return $occupation;
            }
        }

        self::fail("Jour absent de la série : {$jour}");
    }
}
