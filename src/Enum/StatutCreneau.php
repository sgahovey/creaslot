<?php

declare(strict_types=1);

namespace App\Enum;

enum StatutCreneau: string
{
    case DISPONIBLE = 'DISPONIBLE';
    case RESERVE    = 'RESERVE';
    case ANNULE     = 'ANNULE';
    case PASSE      = 'PASSE';
}
