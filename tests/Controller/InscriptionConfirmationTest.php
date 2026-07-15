<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Repository\UtilisateurRepository;
use App\Service\VerificationEmailService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

/**
 * Test fonctionnel de la vérification d'email à l'auto-inscription auditeur (US-12.4).
 *
 * Couvre : inscription → compte non vérifié + email envoyé, confirmation via lien signé,
 * refus d'un lien altéré, blocage de la connexion avant confirmation, renvoi anti-énumération,
 * et non-régression (un compte vérifié se connecte).
 *
 * Emails ASYNC en test (pas d'override test/messenger.yaml) → assertQueuedEmailCount.
 * Ces tests MUTENT la BDD ; comptes jetables à email marqueur, purgés en tearDown.
 */
final class InscriptionConfirmationTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private const string MARQUEUR_TEST = '@verif-email-test.local';
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

    public function test_inscription_cree_un_compte_non_verifie_et_envoie_un_email(): void
    {
        $email = 'nouveau-' . uniqid() . self::MARQUEUR_TEST;

        $this->inscrire($email);

        self::assertResponseRedirects('/inscription/email-envoye');
        self::assertQueuedEmailCount(1);

        $utilisateur = $this->utilisateurEnBase($email);
        self::assertFalse($utilisateur->isEmailVerifie(), 'Le compte doit être créé non vérifié.');
        self::assertTrue($utilisateur->isEstActif(), 'est_actif reste vrai (réservé au contrôle admin).');
    }

    public function test_un_lien_valide_confirme_le_compte(): void
    {
        $email = 'confirme-' . uniqid() . self::MARQUEUR_TEST;
        $utilisateur = $this->creerCompte($email, emailVerifie: false);
        $id = (int) $utilisateur->getId();

        // Signature générée par le bundle ; Request::create reconstruit une requête dont
        // l'hôte correspond exactement à l'URL signée → validation déterministe.
        $lienSigne = $this->genererLienSigne($id, $email);
        $requete = Request::create($lienSigne);
        static::getContainer()->get(VerificationEmailService::class)->confirmer($requete, $utilisateur);

        self::assertTrue($this->utilisateurEnBase($email)->isEmailVerifie());
    }

    public function test_un_lien_altere_est_refuse_par_le_controleur(): void
    {
        $email = 'altere-' . uniqid() . self::MARQUEUR_TEST;
        $utilisateur = $this->creerCompte($email, emailVerifie: false);
        $id = (int) $utilisateur->getId();

        // Signature bidon : le contrôleur doit répondre de façon neutre, sans confirmer.
        $this->client->request('GET', sprintf(
            '/inscription/verifier-email?id=%d&expires=%d&token=faux&signature=faux',
            $id,
            time() + 3600,
        ));

        self::assertResponseRedirects('/connexion');
        self::assertFalse($this->utilisateurEnBase($email)->isEmailVerifie());
    }

    public function test_connexion_avant_confirmation_est_bloquee(): void
    {
        $email = 'bloque-' . uniqid() . self::MARQUEUR_TEST;
        $this->creerCompte($email, emailVerifie: false);

        $this->soumettreConnexion($email, self::MOT_DE_PASSE);

        self::assertResponseRedirects('/connexion');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Renvoyer le lien de confirmation', $crawler->html());
    }

    public function test_un_compte_verifie_peut_se_connecter(): void
    {
        $email = 'verifie-' . uniqid() . self::MARQUEUR_TEST;
        $this->creerCompte($email, emailVerifie: true);

        $this->soumettreConnexion($email, self::MOT_DE_PASSE);

        // Succès d'authentification : redirection vers la cible par défaut (/), pas /connexion.
        self::assertResponseRedirects('/');
    }

    public function test_renvoi_pour_un_compte_non_confirme_envoie_un_email(): void
    {
        $email = 'renvoi-' . uniqid() . self::MARQUEUR_TEST;
        $this->creerCompte($email, emailVerifie: false);

        $this->soumettreRenvoi($email);

        self::assertResponseRedirects('/inscription/email-envoye');
        self::assertQueuedEmailCount(1);
    }

    public function test_renvoi_pour_un_email_inconnu_reste_neutre(): void
    {
        $this->soumettreRenvoi('inconnu-' . uniqid() . self::MARQUEUR_TEST);

        // Même réponse neutre, mais aucun email envoyé (anti-énumération).
        self::assertResponseRedirects('/inscription/email-envoye');
        self::assertQueuedEmailCount(0);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function inscrire(string $email): void
    {
        $crawler = $this->client->request('GET', '/inscription');
        $token = (string) $crawler->filter('input[name="inscription[_token]"]')->attr('value');

        $this->client->request('POST', '/inscription', ['inscription' => [
            'prenom'     => 'Test',
            'nom'        => 'Verif',
            'email'      => $email,
            'motDePasse' => ['first' => self::MOT_DE_PASSE, 'second' => self::MOT_DE_PASSE],
            'cgu'        => '1',
            '_token'     => $token,
        ]]);
    }

    private function genererLienSigne(int $id, string $email): string
    {
        $helper = static::getContainer()->get(VerifyEmailHelperInterface::class);

        return $helper->generateSignature(
            'app_verify_email',
            (string) $id,
            $email,
            ['id' => (string) $id],
        )->getSignedUrl();
    }

    private function soumettreConnexion(string $email, string $motDePasse): void
    {
        $crawler = $this->client->request('GET', '/connexion');
        $formulaire = $crawler->selectButton('Se connecter')->form();
        $formulaire['email'] = $email;
        $formulaire['password'] = $motDePasse;
        $this->client->submit($formulaire);
    }

    private function soumettreRenvoi(string $email): void
    {
        $crawler = $this->client->request('GET', '/inscription/renvoyer-confirmation');
        $formulaire = $crawler->selectButton('Renvoyer le lien')->form();
        $formulaire['renvoi_confirmation[email]'] = $email;
        $this->client->submit($formulaire);
    }

    private function creerCompte(string $email, bool $emailVerifie): Utilisateur
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $utilisateur = (new Utilisateur())
            ->setEmail($email)
            ->setPrenom('Test')
            ->setNom('Verif')
            ->setRole(RoleUtilisateur::AUDITEUR)
            ->setEstActif(true)
            ->setEmailVerifie($emailVerifie);
        $utilisateur->setMotDePasseHash($hasher->hashPassword($utilisateur, self::MOT_DE_PASSE));

        $entityManager->persist($utilisateur);
        $entityManager->flush();

        return $utilisateur;
    }

    private function utilisateurEnBase(string $email): Utilisateur
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $utilisateur = static::getContainer()->get(UtilisateurRepository::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(Utilisateur::class, $utilisateur);

        return $utilisateur;
    }
}
