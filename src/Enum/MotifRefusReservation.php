<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Motif metier pour lequel un creneau ne peut pas etre reserve.
 * Le service expose ce motif ; le controleur le traduit en message utilisateur.
 */
enum MotifRefusReservation
{
    case CreneauInactifOuPasse;
    case CreneauDejaReserve;
    case ProprietaireInactif;
    case PropreCreneau;
}
