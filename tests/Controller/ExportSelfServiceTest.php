<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test fonctionnel de l'export RGPD en self-service (US-5.6).
 *
 * L'utilisateur télécharge SES propres données. Contrairement à la voie admin,
 * le self-service ne crée AUCUNE entrée de journal (trace Monolog uniquement).
 */
final class ExportSelfServiceTest extends WebTestCase
{
    private const EMAIL_AUDITEUR = 'creaslotdemo+xavier@gmail.com';

    private KernelBrowser $client;
    private UtilisateurRepository $utilisateurRepository;

    protected function setUp(): void
    {
        $this->client = static::createClient(['environment' => 'test']);
        $this->utilisateurRepository = static::getContainer()->get(UtilisateurRepository::class);
    }

    public function test_auditeur_telecharge_ses_donnees_sans_journaliser(): void
    {
        $auditeur = $this->recupererUtilisateur(self::EMAIL_AUDITEUR);

        $entreesAvant = $this->nombreEntreesJournal();

        $this->client->loginUser($auditeur);
        $this->client->request('GET', '/mes-donnees/export');

        self::assertResponseIsSuccessful();
        $reponse = $this->client->getResponse();

        self::assertStringContainsString('application/json', (string) $reponse->headers->get('Content-Type'));
        self::assertStringContainsString(
            'attachment; filename="creaslot-mes-donnees-',
            (string) $reponse->headers->get('Content-Disposition'),
        );

        // Le corps contient SES propres données (son email).
        self::assertStringContainsString(self::EMAIL_AUDITEUR, (string) $reponse->getContent());

        // Self-service = Monolog only : aucune entrée de journal créée.
        self::assertSame($entreesAvant, $this->nombreEntreesJournal());
    }

    public function test_export_self_service_redirige_si_non_authentifie(): void
    {
        $this->client->request('GET', '/mes-donnees/export');

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertResponseRedirects();
    }

    public function test_utilisateur_voit_ses_donnees_a_l_ecran(): void
    {
        $auditeur = $this->recupererUtilisateur(self::EMAIL_AUDITEUR);

        $this->client->loginUser($auditeur);
        $this->client->request('GET', '/mes-donnees');

        self::assertResponseIsSuccessful();
        $contenu = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString(self::EMAIL_AUDITEUR, $contenu);
        self::assertStringContainsString($auditeur->getNom(), $contenu);
        // Lien vers l'export JSON (portabilité).
        self::assertStringContainsString('/mes-donnees/export', $contenu);
    }

    public function test_vue_donnees_redirige_si_non_authentifie(): void
    {
        $this->client->request('GET', '/mes-donnees');

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertResponseRedirects();
    }

    private function nombreEntreesJournal(): int
    {
        return (int) static::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('SELECT COUNT(j.id) FROM App\Entity\JournalAdmin j')
            ->getSingleScalarResult();
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
