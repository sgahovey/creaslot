<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Creneau;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Security\CreneauVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class CreneauVoterTest extends TestCase
{
    private CreneauVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new CreneauVoter();
    }

    public function test_personnel_peut_editer_son_propre_creneau(): void
    {
        $personnel = $this->creerUtilisateur(1, RoleUtilisateur::PERSONNEL);
        $creneau   = $this->creerCreneau(10, $personnel);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->creerToken($personnel), $creneau, [CreneauVoter::EDIT]),
        );
    }

    public function test_personnel_ne_peut_pas_editer_creneau_dun_autre(): void
    {
        $personnel        = $this->creerUtilisateur(1, RoleUtilisateur::PERSONNEL);
        $autrePersonnel   = $this->creerUtilisateur(2, RoleUtilisateur::PERSONNEL);
        $creneau          = $this->creerCreneau(10, $autrePersonnel);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->creerToken($personnel), $creneau, [CreneauVoter::EDIT]),
        );
    }

    public function test_super_admin_peut_editer_tous_les_creneaux(): void
    {
        $superAdmin     = $this->creerUtilisateur(99, RoleUtilisateur::SUPER_ADMIN);
        $autrePersonnel = $this->creerUtilisateur(1, RoleUtilisateur::PERSONNEL);
        $creneau        = $this->creerCreneau(10, $autrePersonnel);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->creerToken($superAdmin), $creneau, [CreneauVoter::EDIT]),
        );
    }

    public function test_auditeur_ne_peut_pas_editer_un_creneau(): void
    {
        $auditeur  = $this->creerUtilisateur(5, RoleUtilisateur::AUDITEUR);
        $personnel = $this->creerUtilisateur(1, RoleUtilisateur::PERSONNEL);
        $creneau   = $this->creerCreneau(10, $personnel);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->creerToken($auditeur), $creneau, [CreneauVoter::EDIT]),
        );
    }

    public function test_personnel_peut_supprimer_son_propre_creneau(): void
    {
        $personnel = $this->creerUtilisateur(1, RoleUtilisateur::PERSONNEL);
        $creneau   = $this->creerCreneau(10, $personnel);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->creerToken($personnel), $creneau, [CreneauVoter::DELETE]),
        );
    }

    public function test_tout_utilisateur_authentifie_peut_voir_un_creneau(): void
    {
        $auditeur  = $this->creerUtilisateur(5, RoleUtilisateur::AUDITEUR);
        $personnel = $this->creerUtilisateur(1, RoleUtilisateur::PERSONNEL);
        $creneau   = $this->creerCreneau(10, $personnel);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->creerToken($auditeur), $creneau, [CreneauVoter::VIEW]),
        );
    }

    public function test_abstain_sur_attribut_non_supporte(): void
    {
        $personnel = $this->creerUtilisateur(1, RoleUtilisateur::PERSONNEL);
        $creneau   = $this->creerCreneau(10, $personnel);

        $this->assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->creerToken($personnel), $creneau, ['ATTRIBUT_INCONNU']),
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

    private function creerCreneau(int $id, Utilisateur $personnel): Creneau
    {
        $creneau = new Creneau();
        $creneau->setUtilisateur($personnel);

        $prop = new \ReflectionProperty(Creneau::class, 'id');
        $prop->setValue($creneau, $id);

        return $creneau;
    }

    private function creerToken(Utilisateur $utilisateur): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($utilisateur);

        return $token;
    }
}
