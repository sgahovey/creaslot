<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test fonctionnel du tableau de bord Super-admin (US-5.1).
 *
 * Couvre la sécurité (accès inter-rôles) et le rendu des KPIs. S'appuie sur les
 * comptes de fixtures : Super-admin, Marie Dupont (Personnel), Xavier Dijoux
 * (Auditeur). Lecture seule : aucun GET ne mute la BDD.
 */
final class DashboardControllerTest extends WebTestCase
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

    public function test_dashboard_accessible_au_super_admin_affiche_les_kpis(): void
    {
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_SUPER_ADMIN));

        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        $contenu = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Réservations à venir', $contenu);
        self::assertStringContainsString('Taux d\'occupation', $contenu);
        // US-5.2 : le graphique d'occupation (contrôleur Stimulus + alternative RGAA).
        self::assertStringContainsString('data-controller="graphique-occupation"', $contenu);
        self::assertStringContainsString('Voir les données du graphique', $contenu);
    }

    public function test_dashboard_refuse_au_personnel(): void
    {
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_PERSONNEL));

        $this->client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_dashboard_refuse_a_l_auditeur(): void
    {
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_AUDITEUR));

        $this->client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_dashboard_redirige_si_non_authentifie(): void
    {
        $this->client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertResponseRedirects();
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
