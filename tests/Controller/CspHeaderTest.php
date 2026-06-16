<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Service;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Repository\ServiceRepository;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Vérifie la Content-Security-Policy posée par CspResponseListener (US-9.2, OWASP A05) :
 * en-tête présent sur le HTML avec un `script-src 'self' 'nonce-...'`, nonce de l'en-tête
 * IDENTIQUE à celui porté par les <script> inline, et ABSENCE de CSP sur le JSON de l'API.
 *
 * Compte Personnel jetable (marqueur `@csp-test.local`) supprimé en tearDown.
 */
final class CspHeaderTest extends WebTestCase
{
    private const string MARQUEUR_TEST = '@csp-test.local';
    private const string MOT_DE_PASSE = 'MotDePasseValide!2026';

    private KernelBrowser $client;
    private string $emailTest;

    protected function setUp(): void
    {
        $this->client = static::createClient(['environment' => 'test']);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->emailTest = 'csp-' . uniqid() . self::MARQUEUR_TEST;
        $utilisateur = (new Utilisateur())
            ->setEmail($this->emailTest)
            ->setPrenom('Csp')
            ->setNom('Test')
            ->setRole(RoleUtilisateur::PERSONNEL)
            ->setEstActif(true)
            ->setService($this->unServiceActif());
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

    public function test_page_publique_porte_une_csp_avec_nonce_coherent(): void
    {
        $this->client->request('GET', '/connexion');

        self::assertResponseIsSuccessful();
        $this->assertCspEtNonceCoherents();
    }

    public function test_page_authentifiee_porte_une_csp_avec_nonce_coherent(): void
    {
        $this->client->loginUser($this->utilisateurEnBase());

        // Page Personnel avec un <script> inline nonce-é (formulaire de créneau).
        $this->client->request('GET', '/creneau/nouveau');

        self::assertResponseIsSuccessful();
        $this->assertCspEtNonceCoherents();
    }

    public function test_endpoint_json_api_n_a_pas_de_csp(): void
    {
        $this->client->loginUser($this->utilisateurEnBase());

        $this->client->request('GET', '/api/creneaux');

        self::assertResponseIsSuccessful();
        $reponse = $this->client->getResponse();
        self::assertStringContainsString('application/json', (string) $reponse->headers->get('Content-Type'));
        self::assertFalse(
            $reponse->headers->has('Content-Security-Policy'),
            'Le JSON de l\'API ne doit pas porter de Content-Security-Policy (exclusion HTML-only).',
        );
    }

    /**
     * Asserte que la réponse courante porte une CSP avec script-src 'self' + 'nonce-...',
     * et que ce nonce est exactement celui des <script> inline du HTML.
     */
    private function assertCspEtNonceCoherents(): void
    {
        $reponse = $this->client->getResponse();
        $csp = (string) $reponse->headers->get('Content-Security-Policy');

        self::assertStringContainsString("script-src 'self' 'nonce-", $csp);

        self::assertSame(
            1,
            preg_match("/script-src 'self' 'nonce-([A-Za-z0-9+\/=]+)'/", $csp, $correspondances),
            'Le nonce est introuvable dans le script-src de la CSP.',
        );
        $nonce = $correspondances[1];

        self::assertStringContainsString(
            'nonce="' . $nonce . '"',
            (string) $reponse->getContent(),
            'Les <script> inline doivent porter le nonce annoncé dans l\'en-tête CSP.',
        );
    }

    private function utilisateurEnBase(): Utilisateur
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $utilisateur = static::getContainer()->get(UtilisateurRepository::class)->findOneBy(['email' => $this->emailTest]);
        self::assertInstanceOf(Utilisateur::class, $utilisateur);

        return $utilisateur;
    }

    private function unServiceActif(): Service
    {
        $service = static::getContainer()->get(ServiceRepository::class)->findActifs()[0] ?? null;
        self::assertInstanceOf(Service::class, $service, 'Aucun service actif en fixtures.');

        return $service;
    }
}
