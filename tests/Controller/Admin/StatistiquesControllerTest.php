<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test fonctionnel de la page Statistiques Super-admin (US-5.8).
 *
 * Couvre la sécurité (accès inter-rôles) et le rendu serveur (libellé de fenêtre,
 * tables RGAA des deux axes, data-attributes du contrôleur Stimulus). Vérifie aussi
 * la non-régression RGPD : la page n'expose aucune donnée nominative. S'appuie sur
 * les comptes de fixtures (cf. DashboardControllerTest). Lecture seule.
 */
final class StatistiquesControllerTest extends WebTestCase
{
    private const EMAIL_SUPER_ADMIN = 'creaslotdemo+admin@gmail.com';
    private const EMAIL_PERSONNEL = 'creaslotdemo+marie@gmail.com';
    private const EMAIL_AUDITEUR = 'creaslotdemo+xavier@gmail.com';

    private KernelBrowser $client;
    private UtilisateurRepository $utilisateurRepository;

    protected function setUp(): void
    {
        $this->client = static::createClient(['environment' => 'test']);
        $this->utilisateurRepository = static::getContainer()->get(UtilisateurRepository::class);
    }

    public function test_statistiques_accessible_au_super_admin_affiche_les_deux_axes(): void
    {
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_SUPER_ADMIN));

        $this->client->request('GET', '/admin/statistiques');

        self::assertResponseIsSuccessful();
        $contenu = (string) $this->client->getResponse()->getContent();
        // Libellé de la fenêtre prospective (créneaux à venir).
        self::assertStringContainsString('Occupation des créneaux à venir', $contenu);
        // Conteneur Stimulus + ses deux séries (rendu serveur, pas d'endpoint JSON).
        self::assertStringContainsString('data-controller="statistiques"', $contenu);
        self::assertStringContainsString('data-statistiques-service-value', $contenu);
        self::assertStringContainsString('data-statistiques-type-value', $contenu);
        // Alternatives textuelles RGAA des deux axes.
        self::assertStringContainsString('Voir les données par service', $contenu);
        self::assertStringContainsString('Voir les données par type', $contenu);
    }

    public function test_statistiques_refuse_au_personnel(): void
    {
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_PERSONNEL));

        $this->client->request('GET', '/admin/statistiques');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_statistiques_refuse_a_l_auditeur(): void
    {
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_AUDITEUR));

        $this->client->request('GET', '/admin/statistiques');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_statistiques_redirige_si_non_authentifie(): void
    {
        $this->client->request('GET', '/admin/statistiques');

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertResponseRedirects();
    }

    public function test_statistiques_n_expose_aucune_donnee_nominative(): void
    {
        $auditeur = $this->recupererUtilisateur(self::EMAIL_AUDITEUR);
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_SUPER_ADMIN));

        $this->client->request('GET', '/admin/statistiques');

        self::assertResponseIsSuccessful();
        $contenu = (string) $this->client->getResponse()->getContent();
        // La page n'agrège que des compteurs : aucun nom d'auditeur ne doit fuiter.
        self::assertStringNotContainsString($auditeur->getNom(), $contenu);
        self::assertStringNotContainsString($auditeur->getPrenom(), $contenu);
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
