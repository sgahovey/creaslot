<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * Test fonctionnel de la réinitialisation de mot de passe (US-6.2).
 *
 * Couvre l'anti-énumération (compte inconnu ou désactivé → réponse identique),
 * l'envoi de l'email, le cycle complet de réinitialisation, l'usage unique du
 * jeton, l'interdiction de réutiliser le mot de passe actuel, et le caractère
 * public des routes (règle `^/mot-de-passe-oublie` avant le catch-all `^/`).
 *
 * Transport mail en environnement test : ASYNC (pas d'override `test/messenger.yaml` ;
 * le routing global route SendEmailMessage vers `async`). Les emails sont donc
 * mis en file → on vérifie via assertQueuedEmailCount, pas assertEmailCount.
 *
 * Ces tests MUTENT la BDD (mot de passe, demandes de réinitialisation). WebTestCase
 * ne rollback pas : on opère sur des comptes jetables à email marqueur, supprimés
 * en tearDown (avec toutes les ResetPasswordRequest, table peuplée uniquement par
 * les tests). Aucune fixture n'est mutée.
 */
final class ResetPasswordControllerTest extends WebTestCase
{
    use MailerAssertionsTrait;

    /** Suffixe d'email des comptes créés par les tests (nettoyés en tearDown). */
    private const string MARQUEUR_TEST = '@reset-test.local';

    private const string MOT_DE_PASSE_ACTUEL = 'MotDePasseActuel!2024';
    private const string NOUVEAU_MOT_DE_PASSE = 'NouveauPass!2024xyz';

    private KernelBrowser $client;
    private string $emailTest;

    protected function setUp(): void
    {
        $this->client = static::createClient(['environment' => 'test']);

        // Compte jetable actif, mot de passe connu : support des scénarios nominaux.
        $this->emailTest = 'reset-' . uniqid() . self::MARQUEUR_TEST;
        $this->creerCompte($this->emailTest, self::MOT_DE_PASSE_ACTUEL, estActif: true);
    }

    protected function tearDown(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        // Les demandes référencent l'utilisateur (FK) → les supprimer d'abord. La
        // table n'est peuplée que par les tests : purge globale, comme le journal.
        $entityManager->createQuery('DELETE FROM App\Entity\ResetPasswordRequest r')->execute();
        $entityManager->createQuery('DELETE FROM App\Entity\Utilisateur u WHERE u.email LIKE :marqueur')
            ->setParameter('marqueur', '%' . self::MARQUEUR_TEST)
            ->execute();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Demande de réinitialisation (anti-énumération)
    // ---------------------------------------------------------------------

    public function test_demande_pour_un_compte_actif_envoie_un_email(): void
    {
        $this->soumettreDemande($this->emailTest);

        self::assertResponseRedirects('/mot-de-passe-oublie/email-envoye');
        self::assertQueuedEmailCount(1);
        // En dev/test, APP_MAILER_REDIRECT_TO réoriente l'envoi (cf. NotificationService) :
        // le destinataire est l'adresse de redirection si elle est définie, sinon le compte.
        $redirection = $_ENV['APP_MAILER_REDIRECT_TO'] ?? '';
        $destinataireAttendu = $redirection !== '' ? $redirection : $this->emailTest;
        self::assertEmailAddressContains($this->getMailerMessage(), 'To', $destinataireAttendu);
    }

    public function test_demande_pour_un_email_inexistant_ne_revele_rien(): void
    {
        $this->soumettreDemande('inconnu-' . uniqid() . self::MARQUEUR_TEST);

        // Même réponse que pour un compte existant, mais aucun email n'est envoyé.
        self::assertResponseRedirects('/mot-de-passe-oublie/email-envoye');
        self::assertQueuedEmailCount(0);
    }

    public function test_demande_pour_un_compte_desactive_est_ignoree(): void
    {
        $emailDesactive = 'inactif-' . uniqid() . self::MARQUEUR_TEST;
        $this->creerCompte($emailDesactive, self::MOT_DE_PASSE_ACTUEL, estActif: false);

        $this->soumettreDemande($emailDesactive);

        self::assertResponseRedirects('/mot-de-passe-oublie/email-envoye');
        self::assertQueuedEmailCount(0);
    }

    // ---------------------------------------------------------------------
    // Réinitialisation via le jeton
    // ---------------------------------------------------------------------

    public function test_reinitialisation_avec_un_jeton_valide_change_le_mot_de_passe(): void
    {
        $crawler = $this->ouvrirFormulaireReset($this->utilisateurEnBase($this->emailTest));

        $this->soumettreNouveauMotDePasse($crawler, self::NOUVEAU_MOT_DE_PASSE);

        self::assertResponseRedirects('/connexion');
        self::assertTrue($this->motDePasseValide($this->emailTest, self::NOUVEAU_MOT_DE_PASSE));
    }

    public function test_jeton_invalide_est_refuse(): void
    {
        $this->client->request('GET', '/mot-de-passe-oublie/reinitialiser/jeton-bidon-inexistant');
        // Le jeton est rangé en session puis l'URL nettoyée → on suit la redirection.
        $this->client->followRedirect();

        // Jeton invalide : redirection vers la page de demande avec une erreur.
        self::assertResponseRedirects('/mot-de-passe-oublie');
    }

    public function test_jeton_reutilise_apres_succes_est_refuse(): void
    {
        $jeton = $this->genererJeton($this->utilisateurEnBase($this->emailTest));

        // 1er usage : réinitialisation réussie (consomme le jeton).
        $this->client->request('GET', '/mot-de-passe-oublie/reinitialiser/' . $jeton);
        $crawler = $this->client->followRedirect();
        $this->soumettreNouveauMotDePasse($crawler, self::NOUVEAU_MOT_DE_PASSE);
        self::assertResponseRedirects('/connexion');

        // 2e usage du MÊME jeton : refusé (la demande a été supprimée).
        $this->client->request('GET', '/mot-de-passe-oublie/reinitialiser/' . $jeton);
        $this->client->followRedirect();
        self::assertResponseRedirects('/mot-de-passe-oublie');
    }

    // ---------------------------------------------------------------------
    // Nouveau mot de passe identique à l'actuel
    // ---------------------------------------------------------------------

    public function test_nouveau_identique_a_l_actuel_est_refuse_mais_le_jeton_survit(): void
    {
        $crawler = $this->ouvrirFormulaireReset($this->utilisateurEnBase($this->emailTest));

        // 1) Nouveau == actuel → refus 422, jeton NON consommé.
        $this->soumettreNouveauMotDePasse($crawler, self::MOT_DE_PASSE_ACTUEL);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString(
            'Le nouveau mot de passe doit être différent',
            (string) $this->client->getResponse()->getContent(),
        );
        self::assertTrue($this->motDePasseValide($this->emailTest, self::MOT_DE_PASSE_ACTUEL));

        // 2) Même flux (jeton toujours en session), mot de passe DIFFÉRENT → succès.
        $this->soumettreNouveauMotDePasse($this->client->getCrawler(), self::NOUVEAU_MOT_DE_PASSE);

        self::assertResponseRedirects('/connexion');
        self::assertTrue($this->motDePasseValide($this->emailTest, self::NOUVEAU_MOT_DE_PASSE));
    }

    // ---------------------------------------------------------------------
    // Routes publiques (règle ^/mot-de-passe-oublie avant ^/)
    // ---------------------------------------------------------------------

    public function test_la_page_de_demande_est_publique(): void
    {
        $this->client->request('GET', '/mot-de-passe-oublie');

        self::assertResponseIsSuccessful();
    }

    public function test_la_page_de_reinitialisation_n_est_pas_protegee_par_le_catch_all(): void
    {
        $this->client->followRedirects();
        $this->client->request('GET', '/mot-de-passe-oublie/reinitialiser/jeton-bidon');

        // La règle publique passe avant ^/ : on n'est PAS renvoyé vers la connexion,
        // mais sur la page de demande (jeton invalide). Preuve d'accès public.
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('/mot-de-passe-oublie', $this->client->getRequest()->getPathInfo());
        self::assertNotSame('/connexion', $this->client->getRequest()->getPathInfo());
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function soumettreDemande(string $email): void
    {
        $crawler = $this->client->request('GET', '/mot-de-passe-oublie');
        $form = $crawler->selectButton('Envoyer le lien de réinitialisation')->form();
        $form['reset_password_request_form[email]'] = $email;
        $this->client->submit($form);
    }

    /** Génère un jeton réel, l'ouvre et retourne le crawler du formulaire de saisie. */
    private function ouvrirFormulaireReset(Utilisateur $utilisateur): \Symfony\Component\DomCrawler\Crawler
    {
        $jeton = $this->genererJeton($utilisateur);
        $this->client->request('GET', '/mot-de-passe-oublie/reinitialiser/' . $jeton);

        // Le contrôleur range le jeton en session puis redirige vers l'URL sans jeton.
        return $this->client->followRedirect();
    }

    private function genererJeton(Utilisateur $utilisateur): string
    {
        $helper = static::getContainer()->get(ResetPasswordHelperInterface::class);

        return $helper->generateResetToken($utilisateur)->getToken();
    }

    private function soumettreNouveauMotDePasse(\Symfony\Component\DomCrawler\Crawler $crawler, string $motDePasse): void
    {
        $form = $crawler->selectButton('Réinitialiser mon mot de passe')->form();
        $form['change_password_form[plainPassword][first]'] = $motDePasse;
        $form['change_password_form[plainPassword][second]'] = $motDePasse;
        $this->client->submit($form);
    }

    private function creerCompte(string $email, string $motDePasseEnClair, bool $estActif): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $utilisateur = (new Utilisateur())
            ->setEmail($email)
            ->setPrenom('Reset')
            ->setNom('Test')
            ->setRole(RoleUtilisateur::AUDITEUR)
            ->setEstActif($estActif);
        $utilisateur->setMotDePasseHash($hasher->hashPassword($utilisateur, $motDePasseEnClair));

        $entityManager->persist($utilisateur);
        $entityManager->flush();
    }

    private function utilisateurEnBase(string $email): Utilisateur
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $utilisateur = static::getContainer()->get(UtilisateurRepository::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(Utilisateur::class, $utilisateur);

        return $utilisateur;
    }

    private function motDePasseValide(string $email, string $motDePasseEnClair): bool
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        return $hasher->isPasswordValid($this->utilisateurEnBase($email), $motDePasseEnClair);
    }
}
