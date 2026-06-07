<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Source unique de la politique de mot de passe de l'application (résout DT-18).
 *
 * Auparavant, le trio NotBlank + Length(min: 12) + Regex (au moins 1 majuscule,
 * 1 minuscule, 1 chiffre, 1 caractère spécial) et son texte d'aide étaient
 * dupliqués à l'identique dans chaque formulaire saisissant un mot de passe
 * (inscription, administration des comptes, changement self-service, et bientôt
 * réinitialisation). Toute évolution de la politique se répercute désormais ici
 * seulement.
 *
 * Fabrique de contraintes (pas un service) : une nouvelle instance par champ,
 * car un objet Constraint ne doit pas être partagé entre plusieurs champs.
 */
final class ContraintesMotDePasse
{
    /** Texte d'aide affiché sous le champ, décrivant la politique. */
    public const string AIDE = 'Minimum 12 caractères, avec au moins 1 majuscule, 1 minuscule, 1 chiffre et 1 caractère spécial (@ ! ? # _ - etc.).';

    /**
     * Règles de validation d'un mot de passe en clair, dans l'ordre d'évaluation.
     *
     * @return list<Constraint>
     */
    public static function regles(): array
    {
        return [
            new NotBlank(message: 'Le mot de passe est obligatoire.'),
            new Length(
                min: 12,
                minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.',
            ),
            new Regex(
                pattern: '/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[@!?#_\-%&*+=.,;:()\[\]{}\/\\\\|])/',
                message: 'Le mot de passe doit contenir au moins 1 majuscule, 1 minuscule, 1 chiffre et 1 caractère spécial (@ ! ? # _ - etc.).',
            ),
        ];
    }
}
