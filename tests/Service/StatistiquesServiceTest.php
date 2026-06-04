<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\LigneStatistique;
use App\DTO\StatistiquesParAxe;
use App\DTO\StatistiquesTableauBord;
use App\Repository\CreneauRepository;
use App\Service\StatistiquesService;
use PHPUnit\Framework\TestCase;

/**
 * Test unitaire de l'assemblage des statistiques par service / type (US-5.8).
 *
 * CreneauRepository est remplacé par un stub (createStub, pattern DT-3 : doublure
 * sans expectations → zéro notice PHPUnit). On isole la logique de présentation
 * (taux d'occupation, part en %, gardes division par zéro, libellé « Sans service »,
 * totaux) sans BDD.
 */
final class StatistiquesServiceTest extends TestCase
{
    public function test_calcule_taux_d_occupation_et_part_par_service(): void
    {
        $axe = $this->statistiquesAvec(
            parService: [
                1 => ['serviceId' => 1, 'nom' => 'Commercial', 'offre' => 4, 'reserves' => 3],
                2 => ['serviceId' => 2, 'nom' => 'Alternance', 'offre' => 2, 'reserves' => 1],
            ],
            parType: [],
        )->parService;

        $commercial = $this->ligneParLibelle($axe, 'Commercial');
        // taux = 3/4 = 75 % ; part = 3 réservés sur 4 au total = 75 %.
        self::assertSame(75.0, $commercial->tauxOccupation);
        self::assertSame(75.0, $commercial->partReserves);

        $alternance = $this->ligneParLibelle($axe, 'Alternance');
        // taux = 1/2 = 50 % ; part = 1/4 = 25 %.
        self::assertSame(50.0, $alternance->tauxOccupation);
        self::assertSame(25.0, $alternance->partReserves);
    }

    public function test_offre_nulle_donne_un_taux_de_zero_sans_division_par_zero(): void
    {
        $axe = $this->statistiquesAvec(
            parService: [
                1 => ['serviceId' => 1, 'nom' => 'Commercial', 'offre' => 0, 'reserves' => 0],
                2 => ['serviceId' => 2, 'nom' => 'Alternance', 'offre' => 5, 'reserves' => 2],
            ],
            parType: [],
        )->parService;

        self::assertSame(0.0, $this->ligneParLibelle($axe, 'Commercial')->tauxOccupation);
    }

    public function test_total_reserve_nul_donne_une_part_de_zero_sans_division_par_zero(): void
    {
        $axe = $this->statistiquesAvec(
            parService: [],
            parType: [
                10 => ['typeId' => 10, 'libelle' => 'Visio', 'couleurHex' => '#111111', 'offre' => 3, 'reserves' => 0],
                11 => ['typeId' => 11, 'libelle' => 'Présentiel', 'couleurHex' => '#222222', 'offre' => 1, 'reserves' => 0],
            ],
        )->parType;

        self::assertSame(0.0, $this->ligneParLibelle($axe, 'Visio')->partReserves);
        self::assertSame(0.0, $this->ligneParLibelle($axe, 'Présentiel')->partReserves);
    }

    public function test_le_bucket_sans_service_recoit_le_libelle_sans_service(): void
    {
        $axe = $this->statistiquesAvec(
            parService: [
                0 => ['serviceId' => null, 'nom' => null, 'offre' => 2, 'reserves' => 1],
            ],
            parType: [],
        )->parService;

        $ligne = $axe->lignes[0];
        self::assertSame('Sans service', $ligne->libelle);
        self::assertNull($ligne->couleurHex);
    }

    public function test_l_axe_type_conserve_la_couleur_metier(): void
    {
        $axe = $this->statistiquesAvec(
            parService: [],
            parType: [
                10 => ['typeId' => 10, 'libelle' => 'Visio', 'couleurHex' => '#AB12CD', 'offre' => 2, 'reserves' => 1],
            ],
        )->parType;

        self::assertSame('#AB12CD', $this->ligneParLibelle($axe, 'Visio')->couleurHex);
    }

    public function test_calcule_les_totaux_d_offre_et_de_reserves_par_axe(): void
    {
        $statistiques = $this->statistiquesAvec(
            parService: [
                1 => ['serviceId' => 1, 'nom' => 'Commercial', 'offre' => 4, 'reserves' => 3],
                2 => ['serviceId' => 2, 'nom' => 'Alternance', 'offre' => 2, 'reserves' => 1],
            ],
            parType: [
                10 => ['typeId' => 10, 'libelle' => 'Visio', 'couleurHex' => '#111111', 'offre' => 6, 'reserves' => 4],
            ],
        );

        self::assertSame(6, $statistiques->parService->totalOffre);
        self::assertSame(4, $statistiques->parService->totalReserves);
        self::assertSame(6, $statistiques->parType->totalOffre);
        self::assertSame(4, $statistiques->parType->totalReserves);
    }

    public function test_la_fenetre_est_prospective_sur_les_30_prochains_jours(): void
    {
        $avantAppel = new \DateTimeImmutable();

        $statistiques = $this->statistiquesAvec(parService: [], parType: []);

        // Début ≈ maintenant (pas dans le passé) et fin = début + 30 jours.
        self::assertGreaterThanOrEqual($avantAppel, $statistiques->fenetreDebut);
        self::assertGreaterThan($statistiques->fenetreDebut, $statistiques->fenetreFin);
        self::assertSame(30, $statistiques->fenetreDebut->diff($statistiques->fenetreFin)->days);
    }

    /**
     * @param array<int, array{serviceId: int|null, nom: string|null, offre: int, reserves: int}>          $parService
     * @param array<int, array{typeId: int, libelle: string, couleurHex: string, offre: int, reserves: int}> $parType
     */
    private function statistiquesAvec(array $parService, array $parType): StatistiquesTableauBord
    {
        $creneauRepository = $this->createStub(CreneauRepository::class);
        $creneauRepository->method('statistiquesParService')->willReturn($parService);
        $creneauRepository->method('statistiquesParType')->willReturn($parType);

        return (new StatistiquesService($creneauRepository))->calculerStatistiques();
    }

    private function ligneParLibelle(StatistiquesParAxe $axe, string $libelle): LigneStatistique
    {
        foreach ($axe->lignes as $ligne) {
            if ($ligne->libelle === $libelle) {
                return $ligne;
            }
        }

        self::fail("Ligne introuvable : {$libelle}");
    }
}
