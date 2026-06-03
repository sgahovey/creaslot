<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter gérant les autorisations sur l'entité Utilisateur.
 *
 * @extends Voter<string, Utilisateur>
 */
final class UtilisateurVoter extends Voter
{
    /** Un utilisateur peut voir son propre profil. SUPER_ADMIN voit tout. */
    public const string VIEW = 'UTILISATEUR_VIEW';

    /** Un utilisateur peut modifier son propre profil. SUPER_ADMIN peut tout modifier. */
    public const string EDIT = 'UTILISATEUR_EDIT';

    /** Seul le SUPER_ADMIN peut supprimer un compte (action rare, irréversible). */
    public const string DELETE = 'UTILISATEUR_DELETE';

    /**
     * Seul le SUPER_ADMIN peut désactiver un compte.
     * Un SUPER_ADMIN ne peut pas se désactiver lui-même.
     */
    public const string DEACTIVATE = 'UTILISATEUR_DEACTIVATE';

    /**
     * Seul le SUPER_ADMIN peut changer le rôle d'un compte.
     * Un SUPER_ADMIN ne peut pas changer son propre rôle (anti auto-rétrogradation,
     * évite le lock-out). L'invariant « ne pas retirer le dernier super-admin » est
     * vérifié côté contrôleur (état global), pas ici (le Voter reste pur et sans
     * dépendance).
     */
    public const string CHANGE_ROLE = 'UTILISATEUR_CHANGE_ROLE';

    private const array ATTRIBUTS = [self::VIEW, self::EDIT, self::DELETE, self::DEACTIVATE, self::CHANGE_ROLE];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::ATTRIBUTS, true)
            && $subject instanceof Utilisateur;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $utilisateur = $token->getUser();

        if (!$utilisateur instanceof Utilisateur) {
            return false;
        }

        /** @var Utilisateur $subject */
        return match ($attribute) {
            self::VIEW       => $this->peutVoir($subject, $utilisateur),
            self::EDIT       => $this->peutModifier($subject, $utilisateur),
            self::DELETE      => $utilisateur->getRole() === RoleUtilisateur::SUPER_ADMIN,
            self::DEACTIVATE  => $this->peutDesactiver($subject, $utilisateur),
            self::CHANGE_ROLE => $this->peutChangerRole($subject, $utilisateur),
            default           => false,
        };
    }

    private function peutVoir(Utilisateur $cible, Utilisateur $utilisateur): bool
    {
        return $utilisateur->getRole() === RoleUtilisateur::SUPER_ADMIN
            || $cible->getId() === $utilisateur->getId();
    }

    private function peutModifier(Utilisateur $cible, Utilisateur $utilisateur): bool
    {
        return $utilisateur->getRole() === RoleUtilisateur::SUPER_ADMIN
            || $cible->getId() === $utilisateur->getId();
    }

    private function peutDesactiver(Utilisateur $cible, Utilisateur $utilisateur): bool
    {
        if ($utilisateur->getRole() !== RoleUtilisateur::SUPER_ADMIN) {
            return false;
        }

        // Un SUPER_ADMIN ne peut pas se désactiver lui-même — évite le lock-out
        return $cible->getId() !== $utilisateur->getId();
    }

    private function peutChangerRole(Utilisateur $cible, Utilisateur $utilisateur): bool
    {
        if ($utilisateur->getRole() !== RoleUtilisateur::SUPER_ADMIN) {
            return false;
        }

        // Un SUPER_ADMIN ne peut pas changer son propre rôle — évite l'auto-rétrogradation
        return $cible->getId() !== $utilisateur->getId();
    }
}
