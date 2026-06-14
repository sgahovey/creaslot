<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Utilisateur;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Bloque les comptes désactivés AU LOGIN (checkPreAuth lève DisabledException).
 *
 * La désactivation d'un compte DÉJÀ connecté n'est pas couverte ici : checkPreAuth
 * n'est joué qu'à l'authentification, pas à chaque requête. Ce cas est désormais géré
 * par {@see Utilisateur::isEqualTo()} (DT-14) — au refresh du token sur le
 * firewall stateful, un compte devenu inactif diverge de l'état en session, Symfony
 * dé-authentifie le token et redirige vers la connexion à la requête suivante.
 */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof Utilisateur) {
            return;
        }

        if (!$user->isEstActif()) {
            // Exception levée avant la vérification du mot de passe pour bloquer
            // les comptes désactivés sans révéler l'état du compte à l'utilisateur.
            throw new DisabledException('Compte désactivé.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        // Aucune vérification post-authentification requise.
    }
}
