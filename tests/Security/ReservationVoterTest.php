<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Creneau;
use App\Entity\Reservation;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Security\ReservationVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class ReservationVoterTest extends TestCase
{
    private ReservationVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new ReservationVoter();
    }

    // -------------------------------------------------------------------------
    // VIEW
    // -------------------------------------------------------------------------

    public function test_auditeur_peut_voir_sa_propre_reservation(): void
    {
        $auditeur = $this->creerUtilisateur(5, RoleUtilisateur::AUDITEUR);
        $personnel = $this->creerUtilisateur(1, RoleUtilisateur::PERSONNEL);
        $reservation = $this->creerReservation(20, $personnel, $auditeur);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->creerToken($auditeur), $reservation, [ReservationVoter::VIEW]),
        );
    }

    public function test_auditeur_ne_peut_pas_voir_reservation_dun_autre(): void
    {
        $auditeur = $this->creerUtilisateur(5, RoleUtilisateur::AUDITEUR);
        $autreAuditeur = $this->creerUtilisateur(6, RoleUtilisateur::AUDITEUR);
        $personnel = $this->creerUtilisateur(1, RoleUtilisateur::PERSONNEL);
        $reservation = $this->creerReservation(20, $personnel, $autreAuditeur);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->creerToken($auditeur), $reservation, [ReservationVoter::VIEW]),
        );
    }

    public function test_personnel_peut_voir_reservation_sur_son_creneau(): void
    {
        $personnel = $this->creerUtilisateur(1, RoleUtilisateur::PERSONNEL);
        $auditeur = $this->creerUtilisateur(5, RoleUtilisateur::AUDITEUR);
        $reservation = $this->creerReservation(20, $personnel, $auditeur);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->creerToken($personnel), $reservation, [ReservationVoter::VIEW]),
        );
    }

    public function test_personnel_ne_peut_pas_voir_reservation_sur_creneau_dun_autre(): void
    {
        $personnel = $this->creerUtilisateur(1, RoleUtilisateur::PERSONNEL);
        $autrePersonnel = $this->creerUtilisateur(2, RoleUtilisateur::PERSONNEL);
        $auditeur = $this->creerUtilisateur(5, RoleUtilisateur::AUDITEUR);
        $reservation = $this->creerReservation(20, $autrePersonnel, $auditeur);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->creerToken($personnel), $reservation, [ReservationVoter::VIEW]),
        );
    }

    public function test_super_admin_peut_voir_toutes_les_reservations(): void
    {
        $superAdmin = $this->creerUtilisateur(99, RoleUtilisateur::SUPER_ADMIN);
        $personnel = $this->creerUtilisateur(1, RoleUtilisateur::PERSONNEL);
        $auditeur = $this->creerUtilisateur(5, RoleUtilisateur::AUDITEUR);
        $reservation = $this->creerReservation(20, $personnel, $auditeur);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->creerToken($superAdmin), $reservation, [ReservationVoter::VIEW]),
        );
    }

    // -------------------------------------------------------------------------
    // CANCEL
    // -------------------------------------------------------------------------

    public function test_auditeur_peut_annuler_sa_propre_reservation(): void
    {
        $auditeur = $this->creerUtilisateur(5, RoleUtilisateur::AUDITEUR);
        $personnel = $this->creerUtilisateur(1, RoleUtilisateur::PERSONNEL);
        $reservation = $this->creerReservation(20, $personnel, $auditeur);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->creerToken($auditeur), $reservation, [ReservationVoter::CANCEL]),
        );
    }

    public function test_auditeur_ne_peut_pas_annuler_reservation_dun_autre(): void
    {
        $auditeur = $this->creerUtilisateur(5, RoleUtilisateur::AUDITEUR);
        $autreAuditeur = $this->creerUtilisateur(6, RoleUtilisateur::AUDITEUR);
        $personnel = $this->creerUtilisateur(1, RoleUtilisateur::PERSONNEL);
        $reservation = $this->creerReservation(20, $personnel, $autreAuditeur);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->creerToken($auditeur), $reservation, [ReservationVoter::CANCEL]),
        );
    }

    public function test_personnel_ne_peut_pas_annuler_une_reservation(): void
    {
        $personnel = $this->creerUtilisateur(1, RoleUtilisateur::PERSONNEL);
        $auditeur = $this->creerUtilisateur(5, RoleUtilisateur::AUDITEUR);
        $reservation = $this->creerReservation(20, $personnel, $auditeur);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->creerToken($personnel), $reservation, [ReservationVoter::CANCEL]),
        );
    }

    public function test_super_admin_peut_annuler_toutes_les_reservations(): void
    {
        $superAdmin = $this->creerUtilisateur(99, RoleUtilisateur::SUPER_ADMIN);
        $personnel = $this->creerUtilisateur(1, RoleUtilisateur::PERSONNEL);
        $auditeur = $this->creerUtilisateur(5, RoleUtilisateur::AUDITEUR);
        $reservation = $this->creerReservation(20, $personnel, $auditeur);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->creerToken($superAdmin), $reservation, [ReservationVoter::CANCEL]),
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function creerUtilisateur(int $id, RoleUtilisateur $role): Utilisateur
    {
        $utilisateur = new Utilisateur();
        $utilisateur->setRole($role);
        $utilisateur->setEstActif(true);

        $prop = new \ReflectionProperty(Utilisateur::class, 'id');
        $prop->setValue($utilisateur, $id);

        return $utilisateur;
    }

    private function creerCreneau(Utilisateur $personnel): Creneau
    {
        $creneau = new Creneau();
        $creneau->setUtilisateur($personnel);

        return $creneau;
    }

    private function creerReservation(int $id, Utilisateur $personnel, Utilisateur $auditeur): Reservation
    {
        $reservation = new Reservation();
        $reservation->setCreneau($this->creerCreneau($personnel));
        $reservation->setUtilisateur($auditeur);

        $prop = new \ReflectionProperty(Reservation::class, 'id');
        $prop->setValue($reservation, $id);

        return $reservation;
    }

    private function creerToken(Utilisateur $utilisateur): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($utilisateur);

        return $token;
    }
}
