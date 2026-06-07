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
 * Test fonctionnel de la limitation des tentatives de connexion (OWASP A07).
 *
 * `login_throttling` (security.yaml, max_attempts: 5) bloque les tentatives au-delà
 * du seuil par couple (identifiant + IP). Preuve déterministe : après 5 échecs,
 * même un mot de passe CORRECT est rejeté (le limiteur s'interpose avant la
 * vérification des identifiants).
 *
 * Isolation du limiteur : le pool `cache.rate_limiter` (storage des deux limiteurs
 * local/global) est vidé en setUp et tearDown, et chaque test opère sur un compte à
 * email unique — aucune contamination croisée entre tests.
 *
 * Comptes jetables (marqueur `@auth-test.local`) supprimés en tearDown.
 */
final class LoginThrottlingTest extends WebTestCase
{
    private const string MARQUEUR_TEST = '@auth-test.local';
    private const string MOT_DE_PASSE = 'MotDePasseValide!2026';
    private const int MAX_ATTEMPTS = 5;

    private KernelBrowser $client;
    private string $email;

    protected function setUp(): void
    {
        $this->client = static::createClient(['environment' => 'test']);

        $this->reinitialiserLimiteur();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->email = 'throttle-' . uniqid() . self::MARQUEUR_TEST;
        $utilisateur = (new Utilisateur())
            ->setEmail($this->email)
            ->setPrenom('Auth')
            ->setNom('Test')
            ->setRole(RoleUtilisateur::AUDITEUR)
            ->setEstActif(true);
        $utilisateur->setMotDePasseHash($hasher->hashPassword($utilisateur, self::MOT_DE_PASSE));

        $entityManager->persist($utilisateur);
        $entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->reinitialiserLimiteur();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM App\Entity\Utilisateur u WHERE u.email LIKE :marqueur')
            ->setParameter('marqueur', '%' . self::MARQUEUR_TEST)
            ->execute();

        parent::tearDown();
    }

    public function test_connexion_valide_authentifie_l_utilisateur(): void
    {
        // Contrôle positif : le mot de passe est bien valide (sinon le test de
        // throttling ne prouverait rien). Succès → redirection vers la cible par défaut.
        $this->tenterConnexion($this->email, self::MOT_DE_PASSE);

        self::assertResponseRedirects('/');
    }

    public function test_throttling_bloque_apres_cinq_echecs_meme_avec_le_bon_mot_de_passe(): void
    {
        for ($tentative = 1; $tentative <= self::MAX_ATTEMPTS; ++$tentative) {
            $this->tenterConnexion($this->email, 'MauvaisMotDePasse!' . $tentative);
            // Chaque échec renvoie vers la page de connexion.
            self::assertResponseRedirects('/connexion');
        }

        // (MAX+1)ᵉ tentative avec le BON mot de passe : le limiteur s'interpose avant
        // la vérification → l'utilisateur reste non authentifié (retour à /connexion,
        // et non vers la cible « / » d'une connexion réussie).
        $this->tenterConnexion($this->email, self::MOT_DE_PASSE);

        self::assertResponseRedirects('/connexion');
    }

    private function tenterConnexion(string $email, string $motDePasse): void
    {
        $crawler = $this->client->request('GET', '/connexion');
        $formulaire = $crawler->selectButton('Se connecter')->form();
        $formulaire['email'] = $email;
        $formulaire['password'] = $motDePasse;
        $this->client->submit($formulaire);
    }

    private function reinitialiserLimiteur(): void
    {
        // Vide le pool de cache adossé aux deux limiteurs (local + global) pour
        // garantir un compteur de tentatives vierge à chaque test.
        static::getContainer()->get('cache.rate_limiter')->clear();
    }
}
