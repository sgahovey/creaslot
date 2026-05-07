<?php

declare(strict_types=1);

namespace App\Enum;

enum RoleUtilisateur: string
{
    case AUDITEUR    = 'ROLE_AUDITEUR';
    case PERSONNEL   = 'ROLE_PERSONNEL';
    case SUPER_ADMIN = 'ROLE_SUPER_ADMIN';
}
