<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Type d'une Notification in-app (US-4.7), aligné sur les 5 emails Auditeur
 * envoyés par NotificationService (US-4.2 à US-4.6).
 */
enum TypeNotification: string
{
    case CONFIRMATION_RESERVATION = 'CONFIRMATION_RESERVATION';
    case ANNULATION_RESERVATION = 'ANNULATION_RESERVATION';
    case MODIFICATION_COMMENTAIRE = 'MODIFICATION_COMMENTAIRE';
    case SUPPRESSION_CRENEAU = 'SUPPRESSION_CRENEAU';
    case RAPPEL_J1 = 'RAPPEL_J1';

    public function libelle(): string
    {
        return match ($this) {
            self::CONFIRMATION_RESERVATION => 'Confirmation de réservation',
            self::ANNULATION_RESERVATION   => 'Annulation de réservation',
            self::MODIFICATION_COMMENTAIRE => 'Modification du commentaire',
            self::SUPPRESSION_CRENEAU      => 'Suppression de créneau',
            self::RAPPEL_J1                => 'Rappel de rendez-vous',
        };
    }

    /**
     * Icône Bootstrap Icons associée, pour l'affichage des cartes de notification.
     */
    public function icone(): string
    {
        return match ($this) {
            self::CONFIRMATION_RESERVATION => 'bi-calendar-check',
            self::ANNULATION_RESERVATION   => 'bi-calendar-x',
            self::MODIFICATION_COMMENTAIRE => 'bi-chat-left-text',
            self::SUPPRESSION_CRENEAU      => 'bi-trash',
            self::RAPPEL_J1                => 'bi-alarm',
        };
    }
}
