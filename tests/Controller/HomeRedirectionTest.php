<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Vérifie l'aiguillage de la racine `/` (DT-27) : HomeController redirige chaque
 * utilisateur vers son espace selon son rôle, et l'ancienne page de chantier
 * (divulgation de stack, OWASP A05) a disparu.
 *
 * Comptes jetables (marqueur `@home-redirection-test.local`) créés à la volée et
 * supprimés en tearDown, sans toucher aux comptes de fixtures (pattern des
 * WebTests existants, cf. DesactivationSessionTest).
 */
final class HomeRedirectionTest extends WebTestCase
{
    private const string MARQUEUR_TEST = '@home-redirection-test.local';
    private const string MOT_DE_PASSE = 'MotDePasseValide!2026';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient(['environment' => 'test']);
    }

    protected function tearDown(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM App\Entity\Utilisateur u WHERE u.email LIKE :marqueur')
            ->setParameter('marqueur', '%' . self::MARQUEUR_TEST)
            ->execute();

        parent::tearDown();
    }

    public function test_super_admin_est_redirige_vers_le_dashboard(): void
    {
        $this->client->loginUser($this->creerUtilisateur(RoleUtilisateur::SUPER_ADMIN));

        $this->client->request('GET', '/');

        self::assertResponseRedirects('/admin');
    }

    public function test_personnel_est_redirige_vers_l_agenda(): void
    {
        $this->client->loginUser($this->creerUtilisateur(RoleUtilisateur::PERSONNEL));

        $this->client->request('GET', '/');

        self::assertResponseRedirects('/creneau/agenda');
    }

    public function test_auditeur_est_redirige_vers_les_creneaux_disponibles(): void
    {
        $this->client->loginUser($this->creerUtilisateur(RoleUtilisateur::AUDITEUR));

        $this->client->request('GET', '/');

        self::assertResponseRedirects('/creneaux-disponibles');
    }

    public function test_utilisateur_non_authentifie_est_redirige_vers_la_connexion(): void
    {
        // Aucun login : le firewall (access_control ^/ → IS_AUTHENTICATED_FULLY)
        // intercepte avant le contrôleur et renvoie vers la page de connexion.
        $this->client->request('GET', '/');

        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString(
            '/connexion',
            (string) $this->client->getResponse()->headers->get('Location'),
        );
    }

    private function creerUtilisateur(RoleUtilisateur $role): Utilisateur
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $utilisateur = (new Utilisateur())
            ->setEmail('home-' . uniqid() . self::MARQUEUR_TEST)
            ->setPrenom('Home')
            ->setNom('Test')
            ->setRole($role)
            ->setEstActif(true);
        $utilisateur->setMotDePasseHash($hasher->hashPassword($utilisateur, self::MOT_DE_PASSE));

        $entityManager->persist($utilisateur);
        $entityManager->flush();

        return $utilisateur;
    }
}
