<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\OccupationJournaliere;
use App\DTO\TableauBordKpis;
use App\Repository\CreneauRepository;
use App\Repository\ReservationRepository;

/**
 * Calcule les indicateurs (KPIs) du tableau de bord Super-admin (US-5.1).
 *
 * Orchestre les agrégats scalaires de la couche données (aucune hydratation
 * d'entité, aucune boucle PHP sur le dataset) et assemble le DTO de présentation.
 *
 * Pas de `ClockInterface` injectée : `symfony/clock` n'est pas installé et le
 * test unitaire mocke les repositories, donc la logique de calcul (ratio,
 * garde-fou division par zéro) est entièrement vérifiable sans contrôler
 * l'horloge.
 */
final readonly class DashboardService
{
    private const int JOURS_FENETRE_OCCUPATION = 30;

    public function __construct(
        private ReservationRepository $reservationRepository,
        private CreneauRepository $creneauRepository,
    ) {}

    public function calculerKpis(): TableauBordKpis
    {
        $maintenant   = new \DateTimeImmutable();
        $debutFenetre = $maintenant->modify('-' . self::JOURS_FENETRE_OCCUPATION . ' days');

        $reservationsActives = $this->reservationRepository->countActivesAVenir($maintenant);

        $creneauxOffre    = $this->creneauRepository->countActifsDansFenetre($debutFenetre, $maintenant);
        $creneauxReserves = $this->creneauRepository->countReservesActifsDansFenetre($debutFenetre, $maintenant);

        return new TableauBordKpis(
            $reservationsActives,
            $this->calculerTauxOccupation($creneauxReserves, $creneauxOffre),
            $creneauxReserves,
            $creneauxOffre,
            $debutFenetre,
            $maintenant,
        );
    }

    /**
     * Série d'occupation jour par jour pour le graphique (US-5.2), sur les
     * JOURS_FENETRE_OCCUPATION jours calendaires récents finissant aujourd'hui.
     * Les jours sans créneau sont comblés à 0 ; la liste est chronologique.
     *
     * Nuance assumée vs le KPI : le taux d'occupation borne une fenêtre glissante
     * de 30×24 h, tandis que ce graphe décompose les 30 jours calendaires récents.
     *
     * @return list<OccupationJournaliere>
     */
    public function getOccupationParJour(): array
    {
        $maintenant  = new \DateTimeImmutable();
        $premierJour = $maintenant->modify('-' . (self::JOURS_FENETRE_OCCUPATION - 1) . ' days');

        $statistiques = $this->creneauRepository->statistiquesOccupationParJour(
            $premierJour->setTime(0, 0),
            $maintenant,
        );

        $serie = [];
        for ($decalage = 0; $decalage < self::JOURS_FENETRE_OCCUPATION; $decalage++) {
            $jour      = $premierJour->modify('+' . $decalage . ' days')->format('Y-m-d');
            $compteurs = $statistiques[$jour] ?? ['offre' => 0, 'reserves' => 0];

            $serie[] = new OccupationJournaliere($jour, $compteurs['offre'], $compteurs['reserves']);
        }

        return $serie;
    }

    /**
     * Taux d'occupation en pourcentage (0..100, une décimale).
     * Garde-fou : une offre nulle retourne 0.0 (pas de division par zéro).
     */
    private function calculerTauxOccupation(int $reserves, int $offre): float
    {
        if ($offre <= 0) {
            return 0.0;
        }

        return round($reserves / $offre * 100, 1);
    }
}
