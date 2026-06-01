<?php

declare(strict_types=1);

namespace App\Service;

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
