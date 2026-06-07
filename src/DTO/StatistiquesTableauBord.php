<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Vue d'ensemble de la page Statistiques Super-admin (US-5.8) : les deux axes
 * d'analyse (par service, par type) et la fenêtre temporelle libellée.
 *
 * Miroir de TableauBordKpis (US-5.1) : un DTO de présentation immuable, assemblé
 * par StatistiquesService et consommé tel quel par le template.
 */
final readonly class StatistiquesTableauBord
{
    public function __construct(
        public StatistiquesParAxe $parService,
        public StatistiquesParAxe $parType,
        public \DateTimeImmutable $fenetreDebut,
        public \DateTimeImmutable $fenetreFin,
    ) {
    }
}
