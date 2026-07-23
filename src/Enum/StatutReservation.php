<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Statut d'une Reservation dans son cycle de vie : active ou annulée.
 */
enum StatutReservation: string
{
    case ACTIVE = 'ACTIVE';
    case ANNULEE = 'ANNULEE';
}
