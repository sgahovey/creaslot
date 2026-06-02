<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Occupation d'un jour calendaire (US-5.2) : un point du graphique d'occupation.
 *
 * Donnée strictement agrégée (compteurs) : aucune information nominative.
 * `jour` est au format ISO 'YYYY-MM-DD' (jour calendaire Réunion), prêt à être
 * reformaté pour l'affichage côté template/JS.
 */
final readonly class OccupationJournaliere
{
    public function __construct(
        public string $jour,
        public int $offre,
        public int $reserves,
    ) {}
}
