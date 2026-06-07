<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Reservation;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter gérant les autorisations sur l'entité Reservation.
 *
 * @extends Voter<string, Reservation>
 */
final class ReservationVoter extends Voter
{
    /**
     * Auditeur : uniquement sa propre réservation.
     * Personnel : uniquement les réservations sur ses créneaux.
     * SUPER_ADMIN : tout voir.
     */
    public const string VIEW = 'RESERVATION_VIEW';

    /**
     * Seul l'Auditeur propriétaire peut annuler sa réservation.
     * Le Personnel ne peut pas annuler la réservation d'un Auditeur
     * (il désactive son créneau — c'est une action différente).
     * SUPER_ADMIN : peut tout annuler.
     */
    public const string CANCEL = 'RESERVATION_CANCEL';

    private const array ATTRIBUTS = [self::VIEW, self::CANCEL];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::ATTRIBUTS, true)
            && $subject instanceof Reservation;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $utilisateur = $token->getUser();

        if (!$utilisateur instanceof Utilisateur) {
            return false;
        }

        /* @var Reservation $subject */
        return match ($attribute) {
            self::VIEW   => $this->peutVoir($subject, $utilisateur),
            self::CANCEL => $this->peutAnnuler($subject, $utilisateur),
            default      => false,
        };
    }

    private function peutVoir(Reservation $reservation, Utilisateur $utilisateur): bool
    {
        return match ($utilisateur->getRole()) {
            RoleUtilisateur::SUPER_ADMIN => true,
            // Auditeur : uniquement sa propre réservation
            RoleUtilisateur::AUDITEUR => $reservation->getUtilisateur()->getId() === $utilisateur->getId(),
            // Personnel : uniquement les réservations posées sur ses créneaux
            RoleUtilisateur::PERSONNEL => $reservation->getCreneau()->getUtilisateur()->getId() === $utilisateur->getId(),
        };
    }

    private function peutAnnuler(Reservation $reservation, Utilisateur $utilisateur): bool
    {
        if ($utilisateur->getRole() === RoleUtilisateur::SUPER_ADMIN) {
            return true;
        }

        // Seul l'Auditeur propriétaire peut annuler sa réservation
        if ($utilisateur->getRole() !== RoleUtilisateur::AUDITEUR) {
            return false;
        }

        return $reservation->getUtilisateur()->getId() === $utilisateur->getId();
    }
}
