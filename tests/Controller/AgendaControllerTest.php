<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test fonctionnel de l'agenda Personnel (FullCalendar self-hosté, cf. DT-8).
 *
 * Couvre la page agenda et l'endpoint JSON qui l'alimente :
 *  - câblage du contrôleur Stimulus `agenda` dans le HTML rendu ;
 *  - réponse JSON non mise en cache (header `no-store`, fix anti-cache DT-8) ;
 *  - restriction d'accès `ROLE_PERSONNEL` sur l'API.
 *
 * Lecture seule : aucun GET ne mute la BDD, donc pas de transaction/rollback.
 * S'appuie sur les comptes de fixtures (Marie Dupont : Personnel ; Xavier
 * Dijoux : Auditeur).
 */
final class AgendaControllerTest extends WebTestCase
{
    private const EMAIL_PERSONNEL = 'creaslotdemo+marie@gmail.com';
    private const EMAIL_AUDITEUR = 'creaslotdemo+xavier@gmail.com';

    private KernelBrowser $client;
    private UtilisateurRepository $utilisateurRepository;

    protected function setUp(): void
    {
        // Environnement forcé explicitement, comme les tests d'intégration du
        // projet : `.env` définit APP_ENV=dev, donc sans cette option le kernel
        // boote en dev (où `framework.test` est absent → pas de `test.client`).
        $this->client = static::createClient(['environment' => 'test']);
        $this->utilisateurRepository = static::getContainer()->get(UtilisateurRepository::class);
    }

    public function test_page_agenda_en_personnel_cable_le_controleur_stimulus(): void
    {
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_PERSONNEL));

        $this->client->request('GET', '/creneau/agenda');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'data-controller="agenda"',
            (string) $this->client->getResponse()->getContent(),
            'La page agenda doit câbler le contrôleur Stimulus « agenda » (preuve de la migration JS inline → Stimulus, DT-8).',
        );
    }

    public function test_api_creneaux_en_personnel_repond_json_non_mis_en_cache(): void
    {
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_PERSONNEL));

        $this->client->request('GET', '/api/creneaux', [
            'start' => '2026-06-01T00:00:00',
            'end'   => '2026-07-01T00:00:00',
        ]);

        $reponse = $this->client->getResponse();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('application/json', (string) $reponse->headers->get('Content-Type'));
        self::assertStringContainsString(
            'no-store',
            (string) $reponse->headers->get('Cache-Control'),
            'L\'agenda d\'administration ne doit jamais resservir un état périmé (fix anti-cache DT-8).',
        );
    }

    public function test_api_creneaux_refuse_l_acces_a_un_auditeur(): void
    {
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_AUDITEUR));

        $this->client->request('GET', '/api/creneaux');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_api_creneaux_sans_plage_retourne_un_tableau_vide(): void
    {
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_PERSONNEL));

        $this->client->request('GET', '/api/creneaux');

        self::assertResponseIsSuccessful();
        self::assertSame('[]', (string) $this->client->getResponse()->getContent());
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
