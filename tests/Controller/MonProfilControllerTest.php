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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Test fonctionnel de la page self-service « Mon profil » (US-6.1).
 *
 * Ces tests MUTENT la BDD (prénom/nom, mot de passe). WebTestCase ne rollback pas :
 * on travaille sur un compte dédié à l'email marqueur, créé en setUp et supprimé en
 * tearDown (pattern de CompteControllerTest), sans toucher aux comptes de fixtures.
 *
 * Couvre la sécurité (accès, anti-escalade de privilège, re-authentification du mot
 * de passe) et le rendu en lecture seule (email/rôle/service).
 */
final class MonProfilControllerTest extends WebTestCase
{
    /** Suffixe d'email du compte créé par les tests (nettoyé en tearDown). */
    private const string MARQUEUR_TEST = '@profil-test.local';

    private const string MOT_DE_PASSE_ACTUEL = 'MotDePasseActuel!2024';
    private const string NOUVEAU_MOT_DE_PASSE = 'NouveauPass!2024xyz';

    private KernelBrowser $client;
    private string $emailTest;

    protected function setUp(): void
    {
        $this->client = static::createClient(['environment' => 'test']);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $hasher        = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->emailTest = 'profil-' . uniqid() . self::MARQUEUR_TEST;

        $utilisateur = (new Utilisateur())
            ->setEmail($this->emailTest)
            ->setPrenom('Prenom')
            ->setNom('Nom')
            ->setRole(RoleUtilisateur::PERSONNEL)
            ->setEstActif(true)
            ->setService($this->unServiceActif());
        $utilisateur->setMotDePasseHash($hasher->hashPassword($utilisateur, self::MOT_DE_PASSE_ACTUEL));

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

    // ---------------------------------------------------------------------
    // Accès & rendu
    // ---------------------------------------------------------------------

    public function test_page_accessible_affiche_email_role_service_en_lecture_seule(): void
    {
        $utilisateur = $this->utilisateurEnBase();
        $this->client->loginUser($utilisateur);

        $this->client->request('GET', '/mon-profil');

        self::assertResponseIsSuccessful();
        $contenu = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($this->emailTest, $contenu);
        self::assertStringContainsString($utilisateur->getRole()->libelle(), $contenu);
        self::assertStringContainsString((string) $utilisateur->getService()?->getNom(), $contenu);
    }

    public function test_page_redirige_si_non_authentifie(): void
    {
        $this->client->request('GET', '/mon-profil');

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertResponseRedirects();
    }

    // ---------------------------------------------------------------------
    // Édition des informations
    // ---------------------------------------------------------------------

    public function test_informations_valides_mettent_a_jour_prenom_et_nom(): void
    {
        $this->client->loginUser($this->utilisateurEnBase());

        $this->client->request('GET', '/mon-profil');
        $this->client->submitForm('Enregistrer', [
            'mon_profil[prenom]' => 'Camille',
            'mon_profil[nom]'    => 'Hoarau',
        ]);

        self::assertResponseRedirects('/mon-profil');

        $apres = $this->utilisateurEnBase();
        self::assertSame('Camille', $apres->getPrenom());
        self::assertSame('Hoarau', $apres->getNom());
    }

    public function test_anti_escalade_le_payload_forge_n_altere_ni_role_ni_service_ni_email(): void
    {
        $avant = $this->utilisateurEnBase();
        $this->client->loginUser($avant);

        $crawler = $this->client->request('GET', '/mon-profil');
        $token   = $crawler->filter('form[action="/mon-profil/informations"] input[name="mon_profil[_token]"]')->attr('value');

        // Payload forgé : on injecte rôle / service / email, absents du formulaire.
        // Le formulaire n'autorise pas les champs supplémentaires (défaut Symfony) :
        // la requête falsifiée est rejetée en bloc (422), rien n'est écrit.
        $this->client->request('POST', '/mon-profil/informations', [
            'mon_profil' => [
                'prenom'  => 'Legitime',
                'nom'     => 'Modifie',
                'role'    => RoleUtilisateur::SUPER_ADMIN->value,
                'service' => '999999',
                'email'   => 'pirate' . self::MARQUEUR_TEST,
                '_token'  => $token,
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $apres = $this->utilisateurEnBase();
        // Rôle / service / email : INCHANGÉS côté serveur (non mappés, et payload rejeté).
        self::assertSame(RoleUtilisateur::PERSONNEL, $apres->getRole());
        self::assertSame($avant->getService()?->getId(), $apres->getService()?->getId());
        self::assertSame($this->emailTest, $apres->getEmail());
    }

    // ---------------------------------------------------------------------
    // Changement de mot de passe
    // ---------------------------------------------------------------------

    public function test_mot_de_passe_actuel_faux_est_refuse_et_ne_change_rien(): void
    {
        $this->client->loginUser($this->utilisateurEnBase());

        $this->client->request('GET', '/mon-profil');
        $this->client->submitForm('Modifier le mot de passe', [
            'changement_mot_de_passe[motDePasseActuel]'         => 'MauvaisActuel!2024',
            'changement_mot_de_passe[nouveauMotDePasse][first]' => self::NOUVEAU_MOT_DE_PASSE,
            'changement_mot_de_passe[nouveauMotDePasse][second]' => self::NOUVEAU_MOT_DE_PASSE,
        ]);

        // Re-render avec erreur ciblée : statut 422 (formulaire invalide), pas de flush.
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString(
            'Le mot de passe actuel est incorrect.',
            (string) $this->client->getResponse()->getContent(),
        );
        // Inchangé : l'ancien mot de passe reste valide.
        self::assertTrue($this->motDePasseValide(self::MOT_DE_PASSE_ACTUEL));
    }

    public function test_changement_nominal_modifie_le_mot_de_passe_et_garde_la_session(): void
    {
        $this->client->loginUser($this->utilisateurEnBase());

        $this->client->request('GET', '/mon-profil');
        $this->client->submitForm('Modifier le mot de passe', [
            'changement_mot_de_passe[motDePasseActuel]'          => self::MOT_DE_PASSE_ACTUEL,
            'changement_mot_de_passe[nouveauMotDePasse][first]'  => self::NOUVEAU_MOT_DE_PASSE,
            'changement_mot_de_passe[nouveauMotDePasse][second]' => self::NOUVEAU_MOT_DE_PASSE,
        ]);

        self::assertResponseRedirects('/mon-profil');
        self::assertTrue($this->motDePasseValide(self::NOUVEAU_MOT_DE_PASSE));

        // L'utilisateur reste connecté : la requête suivante aboutit (200, pas 302).
        $this->client->request('GET', '/mon-profil');
        self::assertResponseIsSuccessful();
    }

    public function test_nouveau_mot_de_passe_identique_a_l_actuel_est_refuse(): void
    {
        $this->client->loginUser($this->utilisateurEnBase());

        $this->client->request('GET', '/mon-profil');
        $this->client->submitForm('Modifier le mot de passe', [
            'changement_mot_de_passe[motDePasseActuel]'          => self::MOT_DE_PASSE_ACTUEL,
            'changement_mot_de_passe[nouveauMotDePasse][first]'  => self::MOT_DE_PASSE_ACTUEL,
            'changement_mot_de_passe[nouveauMotDePasse][second]' => self::MOT_DE_PASSE_ACTUEL,
        ]);

        // Refus : re-render 422, erreur ciblée sur le champ nouveau, aucun changement.
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        // Sous-chaîne sans apostrophe (Twig l'échappe en &#039; dans le HTML).
        self::assertStringContainsString(
            'Le nouveau mot de passe doit être différent',
            (string) $this->client->getResponse()->getContent(),
        );
        self::assertTrue($this->motDePasseValide(self::MOT_DE_PASSE_ACTUEL));
    }

    public function test_nouveau_mot_de_passe_trop_court_est_refuse(): void
    {
        $this->client->loginUser($this->utilisateurEnBase());

        $this->client->request('GET', '/mon-profil');
        $this->client->submitForm('Modifier le mot de passe', [
            'changement_mot_de_passe[motDePasseActuel]'          => self::MOT_DE_PASSE_ACTUEL,
            'changement_mot_de_passe[nouveauMotDePasse][first]'  => 'Court!1A',
            'changement_mot_de_passe[nouveauMotDePasse][second]' => 'Court!1A',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertTrue($this->motDePasseValide(self::MOT_DE_PASSE_ACTUEL));
    }

    public function test_nouveau_mot_de_passe_sans_caractere_special_est_refuse(): void
    {
        $this->client->loginUser($this->utilisateurEnBase());

        $this->client->request('GET', '/mon-profil');
        $this->client->submitForm('Modifier le mot de passe', [
            'changement_mot_de_passe[motDePasseActuel]'          => self::MOT_DE_PASSE_ACTUEL,
            'changement_mot_de_passe[nouveauMotDePasse][first]'  => 'NouveauPass2024AB',
            'changement_mot_de_passe[nouveauMotDePasse][second]' => 'NouveauPass2024AB',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertTrue($this->motDePasseValide(self::MOT_DE_PASSE_ACTUEL));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** Recharge l'utilisateur de test depuis la BDD (identity map vidée). */
    private function utilisateurEnBase(): Utilisateur
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $utilisateur = static::getContainer()->get(UtilisateurRepository::class)->findOneBy(['email' => $this->emailTest]);
        self::assertInstanceOf(Utilisateur::class, $utilisateur);

        return $utilisateur;
    }

    private function motDePasseValide(string $motDePasseEnClair): bool
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        return $hasher->isPasswordValid($this->utilisateurEnBase(), $motDePasseEnClair);
    }

    private function unServiceActif(): Service
    {
        $service = static::getContainer()->get(ServiceRepository::class)->findActifs()[0] ?? null;
        self::assertInstanceOf(Service::class, $service, 'Aucun service actif en fixtures.');

        return $service;
    }
}
