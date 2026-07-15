<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

/**
 * Levée par UserChecker quand un utilisateur tente de se connecter alors que son
 * adresse email n'a pas encore été confirmée (US-12.4).
 *
 * Distincte du DisabledException (compte désactivé par un admin) : elle permet à la
 * page de connexion d'afficher un message spécifique invitant à confirmer l'email,
 * avec un lien de renvoi — sans confondre « non confirmé » et « désactivé ».
 */
final class EmailNonVerifieException extends CustomUserMessageAccountStatusException
{
}
