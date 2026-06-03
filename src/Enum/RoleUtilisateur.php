<?php

declare(strict_types=1);

namespace App\Enum;

enum RoleUtilisateur: string
{
    case AUDITEUR    = 'ROLE_AUDITEUR';
    case PERSONNEL   = 'ROLE_PERSONNEL';
    case SUPER_ADMIN = 'ROLE_SUPER_ADMIN';

    /**
     * Libellé français du rôle, pour l'affichage (badges, listes déroulantes).
     */
    public function libelle(): string
    {
        return match ($this) {
            self::AUDITEUR    => 'Auditeur',
            self::PERSONNEL   => 'Personnel',
            self::SUPER_ADMIN => 'Super-administrateur',
        };
    }
}
