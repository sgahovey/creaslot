<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Statistiques agrégées d'un axe d'analyse (par service ou par type) — US-5.8.
 *
 * Les totaux servent à la fois au calcul de la part en pourcentage (en amont,
 * dans StatistiquesService) et à l'affichage d'une ligne de synthèse.
 */
final readonly class StatistiquesParAxe
{
    /**
     * @param list<LigneStatistique> $lignes
     */
    public function __construct(
        public array $lignes,
        public int $totalOffre,
        public int $totalReserves,
    ) {}
}
