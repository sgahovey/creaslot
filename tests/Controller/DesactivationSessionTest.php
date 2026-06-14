<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Test fonctionnel de l'invalidation de session à la désactivation (DT-14).
 *
 * Vérifie que la désactivation d'un compte DÉJÀ connecté prend effet à la requête
 * suivante : au refresh du token (firewall stateful), Utilisateur::isEqualTo détecte
 * la divergence d'état et Symfony dé-authentifie l'utilisateur → 302 vers /connexion.
 *
 * Ces tests MUTENT la BDD (estActif) ; WebTestCase ne rollback pas. On travaille sur
 * un compte dédié à l'email marqueur, créé en setUp et supprimé en tearDown (pattern
 * de MonProfilControllerTest), sans toucher aux comptes de fixtures.
 */
final class DesactivationSessionTest extends WebTestCase
{
    private const string MARQUEUR_TEST = '@desactivation-test.local';
    private const string MOT_DE_PASSE = 'MotDePasseValide!2026';

    private KernelBrowser $client;
    private string $email;

    protected function setUp(): void
    {
        $this->client = static::createClient(['environment' => 'test']);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->email = 'desactivation-' . uniqid() . self::MARQUEUR_TEST;
        $utilisateur = (new Utilisateur())
            ->setEmail($this->email)
            ->setPrenom('Session')
            ->setNom('Test')
            ->setRole(RoleUtilisateur::PERSONNEL)
            ->setEstActif(true);
        $utilisateur->setMotDePasseHash($hasher->hashPassword($utilisateur, self::MOT_DE_PASSE));

        $entityManager->persist($utilisateur);
        $entityManager->flush();
    }

    protected function tearDown(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM App\Entity\Utilisateur u WHERE u.email LIKE :marqueur')
            ->setParameter('marqueur', '%' . self::MARQUEUR_TEST)
            ->execute();

        parent::tearDown();
    }

    public function test_desactivation_en_cours_de_session_rejette_a_la_requete_suivante(): void
    {
        $this->connecter();

        // Session active : la page protégée répond 200.
        $this->client->request('GET', '/mon-profil');
        self::assertResponseIsSuccessful();

        // L'admin désactive le compte pendant que la session est ouverte.
        $this->desactiverEnBase();

        // Requête suivante : le token est rafraîchi, l'état diverge → 302 vers /connexion.
        $this->client->request('GET', '/mon-profil');
        self::assertResponseRedirects('/connexion');
    }

    public function test_utilisateur_actif_reste_connecte_entre_deux_requetes(): void
    {
        $this->connecter();

        $this->client->request('GET', '/mon-profil');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/mon-profil');
        self::assertResponseIsSuccessful();
    }

    private function connecter(): void
    {
        $crawler = $this->client->request('GET', '/connexion');
        $formulaire = $crawler->selectButton('Se connecter')->form();
        $formulaire['email'] = $this->email;
        $formulaire['password'] = self::MOT_DE_PASSE;
        $this->client->submit($formulaire);

        self::assertResponseRedirects('/');
    }

    private function desactiverEnBase(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $utilisateur = static::getContainer()->get(UtilisateurRepository::class)->findOneBy(['email' => $this->email]);
        self::assertInstanceOf(Utilisateur::class, $utilisateur);

        $utilisateur->setEstActif(false);
        $entityManager->flush();
    }
}
