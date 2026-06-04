<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Données du changement de mot de passe self-service (US-6.1).
 *
 * Objet de transport du formulaire `ChangementMotDePasseType` : il ne touche pas
 * l'entité Utilisateur (le re-hachage est fait par le contrôleur après vérification
 * du mot de passe actuel). Non `readonly` : les propriétés sont liées par le
 * composant Form. La confirmation est gérée par `RepeatedType`, d'où une seule
 * propriété pour le nouveau mot de passe.
 */
final class ChangementMotDePasse
{
    public ?string $motDePasseActuel = null;

    public ?string $nouveauMotDePasse = null;
}
