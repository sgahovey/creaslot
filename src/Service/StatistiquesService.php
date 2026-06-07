<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\LigneStatistique;
use App\DTO\StatistiquesParAxe;
use App\DTO\StatistiquesTableauBord;
use App\Repository\CreneauRepository;

/**
 * Assemble les statistiques d'occupation par service et par type de RDV pour la
 * page Statistiques Super-admin (US-5.8).
 *
 * Orchestre les agrégats scalaires de CreneauRepository (aucune hydratation, aucune
 * boucle sur le dataset), calcule taux d'occupation et part en pourcentage, et
 * assemble le DTO de présentation. Même esprit que DashboardService (US-5.1).
 */
final readonly class StatistiquesService
{
    /** Profondeur de la fenêtre prospective : les 30 prochains jours. */
    private const int JOURS_FENETRE = 30;
    private const string FUSEAU_REUNION = 'Indian/Reunion';
    private const string LIBELLE_SANS_SERVICE = 'Sans service';

    public function __construct(
        private CreneauRepository $creneauRepository,
    ) {
    }

    /**
     * Statistiques des deux axes sur la fenêtre prospective des 30 prochains jours
     * (de maintenant à maintenant + 30 j) : l'occupation à venir est ce qui éclaire
     * la décision (créneaux encore réservables), pas l'historique déjà consommé.
     *
     * La fenêtre est construite en heure Réunion explicite (Indian/Reunion, UTC+4
     * sans DST) plutôt qu'en s'appuyant sur le fuseau PHP par défaut, pour rester
     * aligné sur l'heure-mur des `date_debut` stockés.
     */
    public function calculerStatistiques(): StatistiquesTableauBord
    {
        $maintenant = new \DateTimeImmutable('now', new \DateTimeZone(self::FUSEAU_REUNION));
        $finFenetre = $maintenant->modify('+' . self::JOURS_FENETRE . ' days');

        $parService = $this->construireAxe(
            $this->normaliserLignesService(
                $this->creneauRepository->statistiquesParService($maintenant, $finFenetre),
            ),
        );
        $parType = $this->construireAxe(
            $this->normaliserLignesType(
                $this->creneauRepository->statistiquesParType($maintenant, $finFenetre),
            ),
        );

        return new StatistiquesTableauBord($parService, $parType, $maintenant, $finFenetre);
    }

    /**
     * Ramène les lignes brutes « par service » à la forme normalisée commune. Le
     * bucket sans rattachement (nom à null) reçoit le libellé « Sans service » ;
     * aucune couleur (la couleur n'a de sens que pour l'axe type).
     *
     * @param array<int, array{serviceId: int|null, nom: string|null, offre: int, reserves: int}> $lignesBrutes
     *
     * @return list<array{libelle: string, couleurHex: string|null, offre: int, reserves: int}>
     */
    private function normaliserLignesService(array $lignesBrutes): array
    {
        $lignes = [];
        foreach ($lignesBrutes as $ligne) {
            $lignes[] = [
                'libelle'    => $ligne['nom'] ?? self::LIBELLE_SANS_SERVICE,
                'couleurHex' => null,
                'offre'      => $ligne['offre'],
                'reserves'   => $ligne['reserves'],
            ];
        }

        return $lignes;
    }

    /**
     * Ramène les lignes brutes « par type » à la forme normalisée commune, en
     * conservant la couleur métier du TypeRdv pour le graphique en doughnut.
     *
     * @param array<int, array{typeId: int, libelle: string, couleurHex: string, offre: int, reserves: int}> $lignesBrutes
     *
     * @return list<array{libelle: string, couleurHex: string|null, offre: int, reserves: int}>
     */
    private function normaliserLignesType(array $lignesBrutes): array
    {
        $lignes = [];
        foreach ($lignesBrutes as $ligne) {
            $lignes[] = [
                'libelle'    => $ligne['libelle'],
                'couleurHex' => $ligne['couleurHex'],
                'offre'      => $ligne['offre'],
                'reserves'   => $ligne['reserves'],
            ];
        }

        return $lignes;
    }

    /**
     * Construit un axe : 1ʳᵉ passe pour les totaux (base de la part en %), 2ᵉ passe
     * pour les lignes avec taux d'occupation et part. Deux passes car la part de
     * chaque ligne dépend du total de l'axe.
     *
     * @param list<array{libelle: string, couleurHex: string|null, offre: int, reserves: int}> $lignesNormalisees
     */
    private function construireAxe(array $lignesNormalisees): StatistiquesParAxe
    {
        $totalOffre = 0;
        $totalReserves = 0;
        foreach ($lignesNormalisees as $ligne) {
            $totalOffre += $ligne['offre'];
            $totalReserves += $ligne['reserves'];
        }

        $lignes = [];
        foreach ($lignesNormalisees as $ligne) {
            $lignes[] = new LigneStatistique(
                $ligne['libelle'],
                $ligne['offre'],
                $ligne['reserves'],
                $this->tauxOccupation($ligne['reserves'], $ligne['offre']),
                $this->part($ligne['reserves'], $totalReserves),
                $ligne['couleurHex'],
            );
        }

        return new StatistiquesParAxe($lignes, $totalOffre, $totalReserves);
    }

    /**
     * Taux d'occupation d'une ligne en pourcentage (0..100, une décimale).
     * Garde-fou : une offre nulle retourne 0.0 (réplique de
     * DashboardService::calculerTauxOccupation).
     */
    private function tauxOccupation(int $reserves, int $offre): float
    {
        if ($offre <= 0) {
            return 0.0;
        }

        return round($reserves / $offre * 100, 1);
    }

    /**
     * Part d'une ligne dans le total réservé de l'axe, en pourcentage (0..100, une
     * décimale). Garde-fou : un total réservé nul retourne 0.0.
     */
    private function part(int $reserves, int $totalReserves): float
    {
        if ($totalReserves <= 0) {
            return 0.0;
        }

        return round($reserves / $totalReserves * 100, 1);
    }
}
