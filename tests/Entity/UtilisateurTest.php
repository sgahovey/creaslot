<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Utilisateur;
use PHPUnit\Framework\TestCase;

final class UtilisateurTest extends TestCase
{
    public function test_getNomComplet_concatenePrenomEtNom(): void
    {
        $u = new Utilisateur();
        $u->setPrenom('Marie');
        $u->setNom('Bernard');

        self::assertSame('Marie Bernard', $u->getNomComplet());
    }

    public function test_getNomComplet_gereLesCaracteresAccentues(): void
    {
        $u = new Utilisateur();
        $u->setPrenom('François');
        $u->setNom('Étienne');

        self::assertSame('François Étienne', $u->getNomComplet());
    }
}
