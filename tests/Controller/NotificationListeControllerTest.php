<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Vérifie l'accès multi-rôles à la liste des notifications in-app (US-11.1) :
 * `/mes-notifications` est accessible à tout utilisateur connecté (auditeur,
 * personnel, super-admin), chacun ne voyant que ses propres notifications.
 *
 * Comptes jetables (marqueur `@notification-liste-test.local`) créés à la volée
 * et supprimés en tearDown, sans toucher aux comptes de fixtures (même pattern
 * que HomeRedirectionTest).
 */
final class NotificationListeControllerTest extends WebTestCase
{
    private const string MARQUEUR_TEST = '@notification-liste-test.local';
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

    /**
     * @return iterable<string, array{RoleUtilisateur}>
     */
    public static function fournirRolesConnectes(): iterable
    {
        yield 'auditeur' => [RoleUtilisateur::AUDITEUR];
        yield 'personnel' => [RoleUtilisateur::PERSONNEL];
        yield 'super_admin' => [RoleUtilisateur::SUPER_ADMIN];
    }

    #[DataProvider('fournirRolesConnectes')]
    public function test_acces_a_mes_notifications_repond_200_pour_chaque_role(RoleUtilisateur $role): void
    {
        $this->client->loginUser($this->creerUtilisateur($role));

        $this->client->request('GET', '/mes-notifications');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mes notifications');
    }

    public function test_utilisateur_non_authentifie_est_redirige_vers_la_connexion(): void
    {
        // Aucun login : IS_AUTHENTICATED_FULLY intercepte avant le contrôleur et
        // renvoie vers la page de connexion.
        $this->client->request('GET', '/mes-notifications');

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
            ->setEmail('notif-' . uniqid() . self::MARQUEUR_TEST)
            ->setPrenom('Notif')
            ->setNom('Test')
            ->setRole($role)
            ->setEstActif(true);
        $utilisateur->setMotDePasseHash($hasher->hashPassword($utilisateur, self::MOT_DE_PASSE));

        $entityManager->persist($utilisateur);
        $entityManager->flush();

        return $utilisateur;
    }
}
