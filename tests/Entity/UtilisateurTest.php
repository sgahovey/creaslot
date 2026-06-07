<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Utilisateur;
use PHPUnit\Framework\TestCase;

final class UtilisateurTest extends TestCase
{
    public function test_get_nom_complet_concatene_prenom_et_nom(): void
    {
        $u = new Utilisateur();
        $u->setPrenom('Marie');
        $u->setNom('Bernard');

        self::assertSame('Marie Bernard', $u->getNomComplet());
    }

    public function test_get_nom_complet_gere_les_caracteres_accentues(): void
    {
        $u = new Utilisateur();
        $u->setPrenom('François');
        $u->setNom('Étienne');

        self::assertSame('François Étienne', $u->getNomComplet());
    }
}
