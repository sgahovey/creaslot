<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Security\UtilisateurVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class UtilisateurVoterTest extends TestCase
{
    private UtilisateurVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new UtilisateurVoter();
    }

    // -------------------------------------------------------------------------
    // VIEW
    // -------------------------------------------------------------------------

    public function test_utilisateur_peut_voir_son_propre_profil(): void
    {
        $utilisateur = $this->creerUtilisateur(1, RoleUtilisateur::AUDITEUR);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->creerToken($utilisateur), $utilisateur, [UtilisateurVoter::VIEW]),
        );
    }

    public function test_utilisateur_ne_peut_pas_voir_profil_dautrui(): void
    {
        $utilisateur = $this->creerUtilisateur(1, RoleUtilisateur::AUDITEUR);
        $cible       = $this->creerUtilisateur(2, RoleUtilisateur::AUDITEUR);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->creerToken($utilisateur), $cible, [UtilisateurVoter::VIEW]),
        );
    }

    public function test_super_admin_peut_voir_tous_les_profils(): void
    {
        $superAdmin = $this->creerUtilisateur(99, RoleUtilisateur::SUPER_ADMIN);
        $auditeur   = $this->creerUtilisateur(1, RoleUtilisateur::AUDITEUR);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->creerToken($superAdmin), $auditeur, [UtilisateurVoter::VIEW]),
        );
    }

    // -------------------------------------------------------------------------
    // EDIT
    // -------------------------------------------------------------------------

    public function test_utilisateur_peut_modifier_son_propre_profil(): void
    {
        $utilisateur = $this->creerUtilisateur(1, RoleUtilisateur::AUDITEUR);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->creerToken($utilisateur), $utilisateur, [UtilisateurVoter::EDIT]),
        );
    }

    public function test_utilisateur_ne_peut_pas_modifier_profil_dautrui(): void
    {
        $utilisateur = $this->creerUtilisateur(1, RoleUtilisateur::AUDITEUR);
        $cible       = $this->creerUtilisateur(2, RoleUtilisateur::AUDITEUR);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->creerToken($utilisateur), $cible, [UtilisateurVoter::EDIT]),
        );
    }

    // -------------------------------------------------------------------------
    // DELETE
    // -------------------------------------------------------------------------

    public function test_super_admin_peut_supprimer_un_utilisateur(): void
    {
        $superAdmin = $this->creerUtilisateur(99, RoleUtilisateur::SUPER_ADMIN);
        $auditeur   = $this->creerUtilisateur(1, RoleUtilisateur::AUDITEUR);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->creerToken($superAdmin), $auditeur, [UtilisateurVoter::DELETE]),
        );
    }

    public function test_non_super_admin_ne_peut_pas_supprimer(): void
    {
        $personnel = $this->creerUtilisateur(1, RoleUtilisateur::PERSONNEL);
        $auditeur  = $this->creerUtilisateur(2, RoleUtilisateur::AUDITEUR);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->creerToken($personnel), $auditeur, [UtilisateurVoter::DELETE]),
        );
    }

    // -------------------------------------------------------------------------
    // DEACTIVATE
    // -------------------------------------------------------------------------

    public function test_super_admin_peut_desactiver_un_autre_utilisateur(): void
    {
        $superAdmin = $this->creerUtilisateur(99, RoleUtilisateur::SUPER_ADMIN);
        $auditeur   = $this->creerUtilisateur(1, RoleUtilisateur::AUDITEUR);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->creerToken($superAdmin), $auditeur, [UtilisateurVoter::DEACTIVATE]),
        );
    }

    public function test_super_admin_ne_peut_pas_se_desactiver_lui_meme(): void
    {
        $superAdmin = $this->creerUtilisateur(99, RoleUtilisateur::SUPER_ADMIN);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->creerToken($superAdmin), $superAdmin, [UtilisateurVoter::DEACTIVATE]),
        );
    }

    public function test_non_super_admin_ne_peut_pas_desactiver(): void
    {
        $personnel = $this->creerUtilisateur(1, RoleUtilisateur::PERSONNEL);
        $auditeur  = $this->creerUtilisateur(2, RoleUtilisateur::AUDITEUR);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->creerToken($personnel), $auditeur, [UtilisateurVoter::DEACTIVATE]),
        );
    }

    // -------------------------------------------------------------------------
    // CHANGE_ROLE
    // -------------------------------------------------------------------------

    public function test_super_admin_peut_changer_le_role_dun_autre(): void
    {
        $superAdmin = $this->creerUtilisateur(99, RoleUtilisateur::SUPER_ADMIN);
        $auditeur   = $this->creerUtilisateur(1, RoleUtilisateur::AUDITEUR);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->creerToken($superAdmin), $auditeur, [UtilisateurVoter::CHANGE_ROLE]),
        );
    }

    public function test_super_admin_ne_peut_pas_changer_son_propre_role(): void
    {
        $superAdmin = $this->creerUtilisateur(99, RoleUtilisateur::SUPER_ADMIN);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->creerToken($superAdmin), $superAdmin, [UtilisateurVoter::CHANGE_ROLE]),
        );
    }

    public function test_non_super_admin_ne_peut_pas_changer_de_role(): void
    {
        $personnel = $this->creerUtilisateur(1, RoleUtilisateur::PERSONNEL);
        $auditeur  = $this->creerUtilisateur(2, RoleUtilisateur::AUDITEUR);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->creerToken($personnel), $auditeur, [UtilisateurVoter::CHANGE_ROLE]),
        );
    }

    // -------------------------------------------------------------------------
    // ACTIVATE
    // -------------------------------------------------------------------------

    public function test_super_admin_peut_activer_un_compte(): void
    {
        $superAdmin = $this->creerUtilisateur(99, RoleUtilisateur::SUPER_ADMIN);
        $cible      = $this->creerUtilisateur(1, RoleUtilisateur::AUDITEUR);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->creerToken($superAdmin), $cible, [UtilisateurVoter::ACTIVATE]),
        );
    }

    public function test_personnel_ne_peut_pas_activer(): void
    {
        $personnel = $this->creerUtilisateur(1, RoleUtilisateur::PERSONNEL);
        $cible     = $this->creerUtilisateur(2, RoleUtilisateur::AUDITEUR);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->creerToken($personnel), $cible, [UtilisateurVoter::ACTIVATE]),
        );
    }

    public function test_auditeur_ne_peut_pas_activer(): void
    {
        $auditeur = $this->creerUtilisateur(1, RoleUtilisateur::AUDITEUR);
        $cible    = $this->creerUtilisateur(2, RoleUtilisateur::AUDITEUR);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->creerToken($auditeur), $cible, [UtilisateurVoter::ACTIVATE]),
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

    private function creerToken(Utilisateur $utilisateur): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($utilisateur);

        return $token;
    }
}
