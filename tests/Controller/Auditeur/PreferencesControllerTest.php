<?php

declare(strict_types=1);

namespace App\Tests\Controller\Auditeur;

use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test fonctionnel de la page de préférences de notifications (PreferencesController, US-4.8).
 *
 * Couvre l'affichage du formulaire prérempli, la persistance d'une modification (canal
 * e-mail des deux types « confort »), et le refus d'accès à un visiteur non authentifié.
 *
 * WebTestCase ne rollback pas : on travaille sur un Auditeur dédié à email marqueur, créé
 * en setUp et supprimé en tearDown (pattern de MonProfilControllerTest), sans toucher aux
 * comptes de fixtures. Les préférences sont éditées via getUser() (pas de Voter : chacun
 * modifie les siennes).
 */
final class PreferencesControllerTest extends WebTestCase
{
    private const string MARQUEUR_TEST = '@preferences-test.local';

    private KernelBrowser $client;
    private string $emailTest;

    protected function setUp(): void
    {
        $this->client = static::createClient(['environment' => 'test']);

        $this->emailTest = 'preferences-' . uniqid() . self::MARQUEUR_TEST;

        // Compte créé avec les deux préférences « confort » à leur défaut (activées).
        $utilisateur = (new Utilisateur())
            ->setEmail($this->emailTest)
            ->setPrenom('Prenom')
            ->setNom('Nom')
            ->setRole(RoleUtilisateur::AUDITEUR)
            ->setEstActif(true)
            ->setEmailVerifie(true)
            ->setMotDePasseHash('placeholder-not-real');

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
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

    public function test_la_page_s_affiche_pour_un_auditeur_connecte(): void
    {
        $this->client->loginUser($this->utilisateurEnBase());

        $this->client->request('GET', '/mes-preferences');

        self::assertResponseIsSuccessful();
        $contenu = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('rappel la veille', $contenu);
        self::assertStringContainsString('Enregistrer mes préférences', $contenu);
    }

    public function test_la_desactivation_d_une_preference_est_persistee(): void
    {
        $this->client->loginUser($this->utilisateurEnBase());

        $formulaire = $this->client->request('GET', '/mes-preferences')
            ->selectButton('Enregistrer mes préférences')->form();
        $this->caseAcocher($formulaire, 'preferences_notification[emailRappelJ1]')->untick();
        $this->client->submit($formulaire);

        self::assertResponseRedirects('/mes-preferences');

        $apres = $this->utilisateurEnBase();
        self::assertFalse($apres->isEmailRappelJ1(), 'La préférence rappel J-1 doit être désactivée.');
        self::assertTrue(
            $apres->isEmailModificationCommentaire(),
            'L\'autre préférence, non touchée, reste activée.',
        );
    }

    public function test_la_reactivation_d_une_preference_desactivee_est_persistee(): void
    {
        $utilisateur = $this->utilisateurEnBase();
        $utilisateur->setEmailRappelJ1(false);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->client->loginUser($this->utilisateurEnBase());

        $formulaire = $this->client->request('GET', '/mes-preferences')
            ->selectButton('Enregistrer mes préférences')->form();
        $this->caseAcocher($formulaire, 'preferences_notification[emailRappelJ1]')->tick();
        $this->client->submit($formulaire);

        self::assertResponseRedirects('/mes-preferences');
        self::assertTrue(
            $this->utilisateurEnBase()->isEmailRappelJ1(),
            'La préférence rappel J-1 doit être réactivée.',
        );
    }

    public function test_un_utilisateur_non_authentifie_est_redirige_vers_la_connexion(): void
    {
        $this->client->request('GET', '/mes-preferences');

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertResponseRedirects();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Récupère une case à cocher du formulaire en restreignant son type : l'accès
     * ArrayAccess d'un Form renvoie un FormField ou un tableau de champs.
     */
    private function caseAcocher(Form $formulaire, string $nom): ChoiceFormField
    {
        $champ = $formulaire[$nom];
        self::assertInstanceOf(ChoiceFormField::class, $champ);

        return $champ;
    }

    /** Recharge l'utilisateur de test depuis la BDD (identity map vidée). */
    private function utilisateurEnBase(): Utilisateur
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $utilisateur = static::getContainer()->get(UtilisateurRepository::class)->findOneBy(['email' => $this->emailTest]);
        self::assertInstanceOf(Utilisateur::class, $utilisateur);

        return $utilisateur;
    }
}
