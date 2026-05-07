<?php

declare(strict_types=1);

namespace App\Enum;

enum StatutReservation: string
{
    case ACTIVE  = 'ACTIVE';
    case ANNULEE = 'ANNULEE';
}
