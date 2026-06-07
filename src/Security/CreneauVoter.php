<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Creneau;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter gérant les autorisations sur l'entité Creneau.
 *
 * @extends Voter<string, Creneau>
 */
final class CreneauVoter extends Voter
{
    /** Tout utilisateur authentifié peut voir un créneau (à raffiner en US-2.x). */
    public const string VIEW = 'CRENEAU_VIEW';

    /** Seul le Personnel créateur ou le SUPER_ADMIN peut modifier un créneau. */
    public const string EDIT = 'CRENEAU_EDIT';

    /** Même logique que EDIT. */
    public const string DELETE = 'CRENEAU_DELETE';

    private const array ATTRIBUTS = [self::VIEW, self::EDIT, self::DELETE];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::ATTRIBUTS, true)
            && $subject instanceof Creneau;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $utilisateur = $token->getUser();

        if (!$utilisateur instanceof Utilisateur) {
            return false;
        }

        /* @var Creneau $subject */
        return match ($attribute) {
            self::VIEW => true, // tout utilisateur authentifié
            self::EDIT,
            self::DELETE => $this->peutModifier($subject, $utilisateur),
            default      => false,
        };
    }

    /**
     * Le Personnel peut modifier ses propres créneaux.
     * Le SUPER_ADMIN peut tout modifier.
     */
    private function peutModifier(Creneau $creneau, Utilisateur $utilisateur): bool
    {
        if ($utilisateur->getRole() === RoleUtilisateur::SUPER_ADMIN) {
            return true;
        }

        // Comparaison par ID pour éviter les faux négatifs avec les proxys Doctrine
        return $creneau->getUtilisateur()->getId() === $utilisateur->getId();
    }
}
