<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Une ligne agrégée de la page Statistiques Super-admin (US-5.8) : un service ou
 * un type de RDV, avec ses compteurs et ratios.
 *
 * Donnée strictement agrégée (compteurs + pourcentages) : aucune information
 * nominative. `couleurHex` n'est renseignée que pour l'axe « type » (couleur
 * métier du TypeRdv, pour le graphique en doughnut).
 */
final readonly class LigneStatistique
{
    public function __construct(
        public string $libelle,
        public int $offre,
        public int $reserves,
        public float $tauxOccupation,
        public float $partReserves,
        public ?string $couleurHex = null,
    ) {}
}
