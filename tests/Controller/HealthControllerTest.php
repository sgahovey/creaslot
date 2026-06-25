<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Vérifie l'endpoint public de supervision GET /health (Uptime Kuma) :
 * accessible sans authentification, renvoie 200 + JSON {status, checks.database}
 * sur le chemin nominal, et n'est pas mis en cache (no-store).
 *
 * Le cas « BDD en panne » (503) n'est volontairement pas testé ici : casser la
 * connexion globale dans un WebTestCase n'est pas réalisable proprement sans
 * polluer les autres tests. On s'en tient au chemin nominal ; la branche d'erreur
 * reste couverte par la logique du contrôleur (try/catch \Throwable).
 */
final class HealthControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient(['environment' => 'test']);
    }

    public function test_health_est_public_et_renvoie_200_avec_bdd_ok(): void
    {
        // Aucun loginUser() : la sonde interroge l'endpoint sans authentification.
        $this->client->request('GET', '/health');

        self::assertResponseIsSuccessful();

        $reponse = $this->client->getResponse();
        $donnees = json_decode((string) $reponse->getContent(), true);

        self::assertIsArray($donnees);
        self::assertSame('ok', $donnees['status']);
        self::assertSame('ok', $donnees['checks']['database']);

        self::assertStringContainsString(
            'no-store',
            (string) $reponse->headers->get('Cache-Control'),
            'Le healthcheck ne doit pas être mis en cache.',
        );
    }

    public function test_health_repond_en_json(): void
    {
        $this->client->request('GET', '/health');

        self::assertStringContainsString(
            'application/json',
            (string) $this->client->getResponse()->headers->get('Content-Type'),
        );
    }
}
