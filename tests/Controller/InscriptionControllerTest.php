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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Test fonctionnel du formulaire d'inscription publique (SecurityController::inscription).
 *
 * Cible la VALIDATION et la sécurité du formulaire : création d'un compte Auditeur non
 * vérifié + e-mail de confirmation, blocage de la connexion tant que l'e-mail n'est pas
 * confirmé, rejet neutre d'une adresse déjà utilisée (anti-énumération, contrainte
 * UniqueEntity), et application des contraintes de mot de passe.
 *
 * Complète InscriptionConfirmationTest (US-12.4), qui couvre le parcours de vérification
 * d'e-mail (lien signé, renvoi) en amorçant les comptes directement : ici, tout part de
 * la SOUMISSION RÉELLE du formulaire d'inscription.
 *
 * Emails ASYNC en test → assertQueuedEmailCount. Ces tests MUTENT la BDD ; comptes
 * jetables à email marqueur, purgés en tearDown, sans toucher aux comptes de fixtures.
 */
final class InscriptionControllerTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private const string MARQUEUR_TEST = '@inscription-test.local';
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

    public function test_une_inscription_valide_cree_un_compte_auditeur_non_verifie_et_envoie_la_confirmation(): void
    {
        $email = 'nouveau-' . uniqid() . self::MARQUEUR_TEST;

        $this->soumettreInscription($email, self::MOT_DE_PASSE);

        self::assertResponseRedirects('/inscription/email-envoye');
        self::assertQueuedEmailCount(1);

        $utilisateur = $this->utilisateurEnBase($email);
        self::assertInstanceOf(Utilisateur::class, $utilisateur, 'Le compte doit être créé.');
        self::assertSame(RoleUtilisateur::AUDITEUR, $utilisateur->getRole(), 'Un inscrit est toujours Auditeur.');
        self::assertFalse($utilisateur->isEmailVerifie(), 'Le compte est créé non vérifié.');
        self::assertTrue($utilisateur->isEstActif(), 'est_actif reste vrai (réservé au contrôle admin).');
        self::assertTrue(
            $this->motDePasseValide($utilisateur, self::MOT_DE_PASSE),
            'Le mot de passe est haché et vérifiable.',
        );
    }

    public function test_un_compte_fraichement_inscrit_ne_peut_pas_se_connecter_avant_confirmation(): void
    {
        $email = 'bloque-' . uniqid() . self::MARQUEUR_TEST;

        $this->soumettreInscription($email, self::MOT_DE_PASSE);
        self::assertResponseRedirects('/inscription/email-envoye');

        // Connexion immédiate, e-mail non confirmé : UserChecker bloque et renvoie vers
        // la page de connexion avec le message ciblé de renvoi (US-12.4).
        $this->soumettreConnexion($email, self::MOT_DE_PASSE);

        self::assertResponseRedirects('/connexion');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Renvoyer le lien de confirmation', $crawler->html());
    }

    public function test_une_adresse_deja_utilisee_est_refusee_sans_creer_de_second_compte(): void
    {
        $email = 'doublon-' . uniqid() . self::MARQUEUR_TEST;
        $this->creerCompteExistant($email);

        $this->soumettreInscription($email, self::MOT_DE_PASSE);

        // Contrainte UniqueEntity : formulaire invalide, message neutre (anti-énumération),
        // aucun second compte, aucun e-mail de confirmation.
        self::assertSame(1, $this->compterComptes($email), 'Aucun second compte ne doit être créé.');
        self::assertQueuedEmailCount(0);
        self::assertStringContainsString(
            'Une erreur est survenue',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function test_un_mot_de_passe_trop_faible_est_refuse_sans_creer_de_compte(): void
    {
        $email = 'faible-' . uniqid() . self::MARQUEUR_TEST;

        // « faible » : trop court et sans majuscule, chiffre ni caractère spécial.
        $this->soumettreInscription($email, 'faible');

        self::assertNull($this->utilisateurEnBase($email), 'Aucun compte ne doit être créé.');
        self::assertQueuedEmailCount(0);
        self::assertStringContainsString(
            'Le mot de passe doit contenir au moins 1 majuscule',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function soumettreInscription(string $email, string $motDePasse): void
    {
        $crawler = $this->client->request('GET', '/inscription');
        $token = (string) $crawler->filter('input[name="inscription[_token]"]')->attr('value');

        $this->client->request('POST', '/inscription', ['inscription' => [
            'prenom'     => 'Alex',
            'nom'        => 'Testeur',
            'email'      => $email,
            'motDePasse' => ['first' => $motDePasse, 'second' => $motDePasse],
            'cgu'        => '1',
            '_token'     => $token,
        ]]);
    }

    private function soumettreConnexion(string $email, string $motDePasse): void
    {
        $crawler = $this->client->request('GET', '/connexion');
        $formulaire = $crawler->selectButton('Se connecter')->form();
        $formulaire['email'] = $email;
        $formulaire['password'] = $motDePasse;
        $this->client->submit($formulaire);
    }

    private function creerCompteExistant(string $email): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $utilisateur = (new Utilisateur())
            ->setEmail($email)
            ->setPrenom('Deja')
            ->setNom('Inscrit')
            ->setRole(RoleUtilisateur::AUDITEUR)
            ->setEstActif(true)
            ->setEmailVerifie(true);
        $utilisateur->setMotDePasseHash($hasher->hashPassword($utilisateur, self::MOT_DE_PASSE));

        $entityManager->persist($utilisateur);
        $entityManager->flush();
    }

    private function utilisateurEnBase(string $email): ?Utilisateur
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return static::getContainer()->get(UtilisateurRepository::class)->findOneBy(['email' => $email]);
    }

    private function compterComptes(string $email): int
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return (int) $entityManager
            ->createQuery('SELECT COUNT(u.id) FROM App\Entity\Utilisateur u WHERE u.email = :email')
            ->setParameter('email', $email)
            ->getSingleScalarResult();
    }

    private function motDePasseValide(Utilisateur $utilisateur, string $motDePasseEnClair): bool
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        return $hasher->isPasswordValid($utilisateur, $motDePasseEnClair);
    }
}
