<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Utilisateur;
use App\Enum\TypeActionJournal;
use App\Repository\JournalAdminRepository;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test fonctionnel de l'export RGPD côté super-admin (US-5.6).
 *
 * Couvre la sécurité (accès inter-rôles), les en-têtes de téléchargement, le
 * contenu JSON et la journalisation (COMPTE_EXPORT). Le journal est vidé en
 * tearDown (peuplé uniquement par les tests).
 */
final class ExportControllerTest extends WebTestCase
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

    public function test_super_admin_exporte_un_compte_et_journalise(): void
    {
        $admin = $this->recupererUtilisateur(self::EMAIL_SUPER_ADMIN);
        $cible = $this->recupererUtilisateur(self::EMAIL_AUDITEUR);

        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/comptes/' . $cible->getId() . '/export');

        self::assertResponseIsSuccessful();
        $reponse = $this->client->getResponse();

        // En-têtes de téléchargement.
        self::assertStringContainsString('application/json', (string) $reponse->headers->get('Content-Type'));
        self::assertStringContainsString(
            'attachment; filename="creaslot-donnees-' . $cible->getId() . '-',
            (string) $reponse->headers->get('Content-Disposition'),
        );

        // Corps = JSON valide contenant le profil.
        $donnees = json_decode((string) $reponse->getContent(), true);
        self::assertIsArray($donnees);
        self::assertArrayHasKey('profil', $donnees);

        // Une entrée COMPTE_EXPORT a été créée (acteur = admin, cible = compte).
        $entree = $this->dernierExportPour((int) $cible->getId());
        self::assertNotNull($entree);
        self::assertSame((int) $admin->getId(), $entree->getActeurId());
    }

    public function test_export_refuse_au_personnel(): void
    {
        $cible = $this->recupererUtilisateur(self::EMAIL_AUDITEUR);
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_PERSONNEL));

        $this->client->request('GET', '/admin/comptes/' . $cible->getId() . '/export');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_export_refuse_a_l_auditeur(): void
    {
        $cible = $this->recupererUtilisateur(self::EMAIL_AUDITEUR);
        $this->client->loginUser($cible);

        $this->client->request('GET', '/admin/comptes/' . $cible->getId() . '/export');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_export_redirige_si_non_authentifie(): void
    {
        $cible = $this->recupererUtilisateur(self::EMAIL_AUDITEUR);

        $this->client->request('GET', '/admin/comptes/' . $cible->getId() . '/export');

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertResponseRedirects();
    }

    public function test_super_admin_consulte_les_donnees_sans_journaliser(): void
    {
        $cible = $this->recupererUtilisateur(self::EMAIL_AUDITEUR);

        $entreesAvant = $this->nombreEntreesJournal();

        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_SUPER_ADMIN));
        $this->client->request('GET', '/admin/comptes/' . $cible->getId() . '/donnees');

        self::assertResponseIsSuccessful();
        $contenu = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString(self::EMAIL_AUDITEUR, $contenu);
        // Lien vers le téléchargement JSON.
        self::assertStringContainsString('/admin/comptes/' . $cible->getId() . '/export', $contenu);

        // Consultation = Monolog only : aucune entrée de journal créée.
        self::assertSame($entreesAvant, $this->nombreEntreesJournal());
    }

    public function test_consultation_refusee_au_personnel(): void
    {
        $cible = $this->recupererUtilisateur(self::EMAIL_AUDITEUR);
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_PERSONNEL));

        $this->client->request('GET', '/admin/comptes/' . $cible->getId() . '/donnees');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_consultation_refusee_a_l_auditeur(): void
    {
        $cible = $this->recupererUtilisateur(self::EMAIL_AUDITEUR);
        $this->client->loginUser($cible);

        $this->client->request('GET', '/admin/comptes/' . $cible->getId() . '/donnees');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_consultation_redirige_si_non_authentifie(): void
    {
        $cible = $this->recupererUtilisateur(self::EMAIL_AUDITEUR);

        $this->client->request('GET', '/admin/comptes/' . $cible->getId() . '/donnees');

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertResponseRedirects();
    }

    private function nombreEntreesJournal(): int
    {
        return (int) static::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('SELECT COUNT(j.id) FROM App\Entity\JournalAdmin j')
            ->getSingleScalarResult();
    }

    private function dernierExportPour(int $cibleId): ?object
    {
        $entrees = static::getContainer()->get(JournalAdminRepository::class)
            ->findPourAdmin(1, 25, TypeActionJournal::COMPTE_EXPORT);

        foreach ($entrees as $entree) {
            if ($entree->getCibleId() === $cibleId) {
                return $entree;
            }
        }

        return null;
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
