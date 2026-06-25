<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Vérifie que les 4 pages légales (US-10.1) sont accessibles SANS authentification
 * et répondent en succès (200) : ce sont des règles PUBLIC_ACCESS placées avant
 * l'attrape-tout `^/` dans security.yaml.
 *
 * Aucune entité créée → pas de tearDown.
 */
final class LegalPagesTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function pagesLegalesProvider(): iterable
    {
        yield 'mentions légales' => ['/mentions-legales'];
        yield 'CGU' => ['/conditions-generales'];
        yield 'confidentialité' => ['/confidentialite'];
        yield 'accessibilité' => ['/accessibilite'];
    }

    #[DataProvider('pagesLegalesProvider')]
    public function test_page_legale_accessible_sans_authentification(string $url): void
    {
        $client = static::createClient(['environment' => 'test']);

        // Aucun loginUser() : on vérifie justement l'accès public.
        $client->request('GET', $url);

        self::assertResponseIsSuccessful();
    }

    public function test_la_page_se_rend_reellement_et_n_est_pas_une_redirection(): void
    {
        $client = static::createClient(['environment' => 'test']);

        $client->request('GET', '/mentions-legales');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mentions légales');
    }
}
