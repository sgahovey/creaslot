<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration des méthodes de gestion des comptes (US-5.3, morceau 1) :
 * UtilisateurRepository::findAllPourAdmin et ::countSuperAdmins.
 *
 * Stratégie « baseline + delta » : les méthodes sont globales, donc on mesure
 * l'état AVANT insertion, on insère un jeu contrôlé en transaction, et on assère
 * le delta. Robuste que la BDD test soit vide ou peuplée de fixtures.
 * Transaction + rollback (pattern CreneauRepositoryQueriesTest).
 */
final class UtilisateurRepositoryAdminTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private UtilisateurRepository $utilisateurRepository;

    protected function setUp(): void
    {
        self::bootKernel(['environment' => 'test']);
        $container = static::getContainer();

        $this->entityManager         = $container->get(EntityManagerInterface::class);
        $this->utilisateurRepository = $container->get(UtilisateurRepository::class);

        $this->entityManager->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        $this->entityManager->close();
        parent::tearDown();
    }

    public function test_findAllPourAdmin_inclut_les_comptes_inactifs_et_trie_par_nom(): void
    {
        $totalAvant = count($this->utilisateurRepository->findAllPourAdmin(1));

        // Nom préfixé 'AAAA' → trié en tête (ORDER BY u.nom ASC), donc présent en page 1.
        $inactif = $this->creerUtilisateur('AAAA-Inactif-' . uniqid(), 'Zoe', RoleUtilisateur::AUDITEUR, false);
        $this->entityManager->flush();

        $paginator = $this->utilisateurRepository->findAllPourAdmin(1);

        // Le compteur global a augmenté d'exactement 1 (compte inactif inclus).
        self::assertSame($totalAvant + 1, count($paginator));

        // Trié par nom : l'inséré 'AAAA-…' arrive en première position de la page 1.
        $page = iterator_to_array($paginator);
        self::assertSame($inactif->getEmail(), $page[0]->getEmail());
        // Et il est bien inactif → les comptes inactifs ne sont pas filtrés.
        self::assertFalse($page[0]->isEstActif());
    }

    public function test_countSuperAdmins_compte_les_comptes_super_admin(): void
    {
        $baseline = $this->utilisateurRepository->countSuperAdmins();

        $this->creerUtilisateur('Test-SuperAdmin-' . uniqid(), 'Alex', RoleUtilisateur::SUPER_ADMIN, true);
        $this->entityManager->flush();

        self::assertSame($baseline + 1, $this->utilisateurRepository->countSuperAdmins());
    }

    private function creerUtilisateur(string $nom, string $prenom, RoleUtilisateur $role, bool $estActif): Utilisateur
    {
        $utilisateur = (new Utilisateur())
            ->setEmail('admin-repo-' . uniqid() . '@test.local')
            ->setNom($nom)
            ->setPrenom($prenom)
            ->setRole($role)
            ->setEstActif($estActif)
            ->setMotDePasseHash('placeholder-not-real');

        $this->entityManager->persist($utilisateur);

        return $utilisateur;
    }
}
