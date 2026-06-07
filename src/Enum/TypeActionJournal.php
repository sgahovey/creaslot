<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Types d'action tracés dans le journal d'administration des comptes (US-5.5).
 */
enum TypeActionJournal: string
{
    case COMPTE_CREATION = 'COMPTE_CREATION';
    case COMPTE_MODIFICATION = 'COMPTE_MODIFICATION';
    case COMPTE_CHANGEMENT_ROLE = 'COMPTE_CHANGEMENT_ROLE';
    case COMPTE_ACTIVATION = 'COMPTE_ACTIVATION';
    case COMPTE_DESACTIVATION = 'COMPTE_DESACTIVATION';
    case COMPTE_EXPORT = 'COMPTE_EXPORT';

    /**
     * Libellé français de l'action, pour l'affichage (badges, filtre).
     */
    public function libelle(): string
    {
        return match ($this) {
            self::COMPTE_CREATION        => 'Création de compte',
            self::COMPTE_MODIFICATION    => 'Modification de compte',
            self::COMPTE_CHANGEMENT_ROLE => 'Changement de rôle',
            self::COMPTE_ACTIVATION      => 'Réactivation de compte',
            self::COMPTE_DESACTIVATION   => 'Désactivation de compte',
            self::COMPTE_EXPORT          => 'Export des données',
        };
    }
}
