<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Test unitaire de Utilisateur::isEqualTo (DT-14).
 *
 * isEqualTo gouverne la dé-authentification au refresh du token : il ne doit retourner
 * true que si l'identifiant, l'état actif et les rôles sont identiques. Le mot de passe
 * est volontairement hors comparaison (un changement de mot de passe ne déconnecte pas).
 */
final class UtilisateurIsEqualToTest extends TestCase
{
    public function test_egal_quand_email_role_et_actif_identiques(): void
    {
        self::assertTrue($this->utilisateur()->isEqualTo($this->utilisateur()));
    }

    public function test_different_quand_etat_actif_differe(): void
    {
        $actif = $this->utilisateur();
        $inactif = $this->utilisateur()->setEstActif(false);

        self::assertFalse($actif->isEqualTo($inactif));
    }

    public function test_different_quand_role_differe(): void
    {
        $personnel = $this->utilisateur();
        $auditeur = $this->utilisateur()->setRole(RoleUtilisateur::AUDITEUR);

        self::assertFalse($personnel->isEqualTo($auditeur));
    }

    public function test_false_quand_l_autre_n_est_pas_un_utilisateur(): void
    {
        $autre = $this->createStub(UserInterface::class);

        self::assertFalse($this->utilisateur()->isEqualTo($autre));
    }

    private function utilisateur(): Utilisateur
    {
        return (new Utilisateur())
            ->setEmail('session@desactivation-test.local')
            ->setRole(RoleUtilisateur::PERSONNEL)
            ->setEstActif(true);
    }
}
