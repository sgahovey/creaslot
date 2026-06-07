<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Indicateurs (KPIs) du tableau de bord Super-admin (US-5.1).
 *
 * Objet de transfert immuable porté du DashboardService vers la vue. Ne contient
 * que des données agrégées (compteurs et ratio) — aucune donnée nominative, par
 * construction (minimisation RGPD).
 *
 * `creneauxReserves` et `creneauxOffre` sont exposés pour permettre l'affichage
 * du détail « x / y » sous le taux d'occupation (lisibilité côté Super-admin).
 * La fenêtre temporelle du taux est portée par `fenetreDebut`/`fenetreFin` pour
 * être rappelée à l'écran.
 */
final readonly class TableauBordKpis
{
    public function __construct(
        public int $reservationsActives,
        public float $tauxOccupation,
        public int $creneauxReserves,
        public int $creneauxOffre,
        public \DateTimeImmutable $fenetreDebut,
        public \DateTimeImmutable $fenetreFin,
    ) {
    }
}
