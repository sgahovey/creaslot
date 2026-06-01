<?php

declare(strict_types=1);

namespace App\Tests\Service;

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

    private function creerServiceAvecCompteurs(int $activesAVenir, int $offre, int $reserves): DashboardService
    {
        $reservationRepository = $this->createStub(ReservationRepository::class);
        $reservationRepository->method('countActivesAVenir')->willReturn($activesAVenir);

        $creneauRepository = $this->createStub(CreneauRepository::class);
        $creneauRepository->method('countActifsDansFenetre')->willReturn($offre);
        $creneauRepository->method('countReservesActifsDansFenetre')->willReturn($reserves);

        return new DashboardService($reservationRepository, $creneauRepository);
    }
}
