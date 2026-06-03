<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\JournalAdmin;
use App\Entity\Utilisateur;
use App\Enum\TypeActionJournal;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test fonctionnel de la consultation du journal d'administration (US-5.5).
 *
 * Couvre la sécurité (accès inter-rôles), le rendu, le filtre et la lecture seule.
 * Le journal n'étant peuplé que par les tests, on le vide en tearDown.
 */
final class JournalControllerTest extends WebTestCase
{
    private const EMAIL_SUPER_ADMIN = 'creaslotdemo+admin@gmail.com';
    private const EMAIL_PERSONNEL   = 'creaslotdemo+marie@gmail.com';
    private const EMAIL_AUDITEUR    = 'creaslotdemo+xavier@gmail.com';

    private KernelBrowser $client;
    private UtilisateurRepository $utilisateurRepository;

    protected function setUp(): void
    {
        $this->client = static::createClient(['environment' => 'test']);
        $this->utilisateurRepository = static::getContainer()->get(UtilisateurRepository::class);
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('DELETE FROM App\Entity\JournalAdmin j')
            ->execute();

        parent::tearDown();
    }

    public function test_journal_accessible_au_super_admin_affiche_les_entrees(): void
    {
        $this->creerEntree(TypeActionJournal::COMPTE_CREATION, 'Cible Visible');

        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_SUPER_ADMIN));
        $this->client->request('GET', '/admin/journal');

        self::assertResponseIsSuccessful();
        $contenu = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Cible Visible', $contenu);
        // Le sélecteur de filtre est présent.
        self::assertStringContainsString('name="type"', $contenu);
        self::assertStringContainsString('Toutes', $contenu);
    }

    public function test_filtre_par_type_n_affiche_que_le_type_choisi(): void
    {
        $this->creerEntree(TypeActionJournal::COMPTE_CREATION, 'CibleAlpha');
        $this->creerEntree(TypeActionJournal::COMPTE_DESACTIVATION, 'CibleBeta');

        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_SUPER_ADMIN));
        $this->client->request('GET', '/admin/journal?type=' . TypeActionJournal::COMPTE_DESACTIVATION->value);

        self::assertResponseIsSuccessful();
        $contenu = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('CibleBeta', $contenu);
        self::assertStringNotContainsString('CibleAlpha', $contenu);
    }

    public function test_journal_refuse_au_personnel(): void
    {
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_PERSONNEL));
        $this->client->request('GET', '/admin/journal');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_journal_refuse_a_l_auditeur(): void
    {
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_AUDITEUR));
        $this->client->request('GET', '/admin/journal');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_journal_redirige_si_non_authentifie(): void
    {
        $this->client->request('GET', '/admin/journal');

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertResponseRedirects();
    }

    public function test_journal_est_en_lecture_seule(): void
    {
        $this->creerEntree(TypeActionJournal::COMPTE_CREATION, 'Cible Visible');

        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_SUPER_ADMIN));
        $this->client->request('GET', '/admin/journal');

        self::assertResponseIsSuccessful();
        // Aucun formulaire de mutation : le seul formulaire est le filtre (method get).
        self::assertStringNotContainsString('method="post"', (string) $this->client->getResponse()->getContent());
    }

    private function creerEntree(TypeActionJournal $type, string $cibleLibelle): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $entree = (new JournalAdmin())
            ->setTypeAction($type)
            ->setActeurId(1)
            ->setActeurLibelle('Super Admin')
            ->setCibleId(99)
            ->setCibleLibelle($cibleLibelle);

        $entityManager->persist($entree);
        $entityManager->flush();
    }

    private function recupererUtilisateur(string $email): Utilisateur
    {
        $utilisateur = $this->utilisateurRepository->findOneBy(['email' => $email]);

        self::assertInstanceOf(
            Utilisateur::class,
            $utilisateur,
            "Compte de fixtures introuvable : {$email}. Charger les fixtures sur la BDD test (cf. DT-6).",
        );

        return $utilisateur;
    }
}
