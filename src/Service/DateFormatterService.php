<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Service de formatage de dates centralisé pour le projet CreaSlot.
 *
 * Centralise tous les formats de date/heure utilisés à travers l'application,
 * avec gestion uniforme de la timezone Indian/Reunion (UTC+4).
 *
 * Pattern : service stateless injectable via DI Symfony.
 * Réutilisable depuis NotificationService et tout autre service/controller/Twig
 * extension nécessitant un formatage de date local Réunion.
 *
 * Tous les formats produits sont en français et adaptés au contexte
 * Cnam Réunion (timezone Indian/Reunion = UTC+4 sans DST).
 *
 * Apparition initiale : refacto d'élimination de duplication post-US-4.5
 * (6 occurrences identiques dans NotificationService US-4.2 à 4.5).
 */
final readonly class DateFormatterService
{
    private const TIMEZONE_REUNION = 'Indian/Reunion';

    /**
     * Formate une date pour le sujet d'email transactionnel.
     *
     * Format produit : "JJ/MM/AAAA à HHhMM" (ex: "26/05/2026 à 14h30").
     * Timezone forcée : Indian/Reunion (UTC+4).
     *
     * Utilisé par les 6 méthodes notifier*() de NotificationService.
     *
     * @param \DateTimeInterface $date La date à formater (UTC, locale, ou autre tz)
     *
     * @return string La date formatée en heure de la Réunion
     */
    public function pourSujetEmail(\DateTimeInterface $date): string
    {
        return $date
            ->setTimezone(new \DateTimeZone(self::TIMEZONE_REUNION))
            ->format('d/m/Y \à H\hi');
    }
}
