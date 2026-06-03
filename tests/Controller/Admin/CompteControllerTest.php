<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Service;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Repository\ServiceRepository;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test fonctionnel de la gestion des comptes Super-admin (US-5.3).
 *
 * Morceau 2 : liste (lecture seule) — sécurité (accès inter-rôles) et rendu.
 * S'appuie sur les comptes de fixtures (Super-admin, Marie/Personnel, Xavier/Auditeur).
 */
final class CompteControllerTest extends WebTestCase
{
    private const EMAIL_SUPER_ADMIN = 'creaslotdemo+admin@gmail.com';
    private const EMAIL_PERSONNEL   = 'creaslotdemo+marie@gmail.com';
    private const EMAIL_AUDITEUR    = 'creaslotdemo+xavier@gmail.com';

    /** Suffixe d'email des comptes créés par les tests (nettoyés en tearDown). */
    private const MARQUEUR_TEST = '@m4-test.local';

    private KernelBrowser $client;
    private UtilisateurRepository $utilisateurRepository;

    protected function setUp(): void
    {
        $this->client = static::createClient(['environment' => 'test']);
        $this->utilisateurRepository = static::getContainer()->get(UtilisateurRepository::class);
    }

    /**
     * Nettoie TOUT compte créé par les tests (déterministe, même après échec) :
     * garantit que countSuperAdmins() reste à 1 (le seul super-admin des fixtures)
     * d'un test à l'autre.
     */
    protected function tearDown(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('DELETE FROM App\Entity\Utilisateur u WHERE u.email LIKE :marqueur')
            ->setParameter('marqueur', '%' . self::MARQUEUR_TEST)
            ->execute();

        parent::tearDown();
    }

    public function test_liste_accessible_au_super_admin_affiche_les_comptes(): void
    {
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_SUPER_ADMIN));

        $this->client->request('GET', '/admin/comptes');

        self::assertResponseIsSuccessful();
        $contenu = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<table', $contenu);
        // Un compte de fixtures doit apparaître (nom + email).
        self::assertStringContainsString('Marie Dupont', $contenu);
        self::assertStringContainsString(self::EMAIL_PERSONNEL, $contenu);
    }

    public function test_liste_refuse_au_personnel(): void
    {
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_PERSONNEL));

        $this->client->request('GET', '/admin/comptes');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_liste_refuse_a_l_auditeur(): void
    {
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_AUDITEUR));

        $this->client->request('GET', '/admin/comptes');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_liste_redirige_si_non_authentifie(): void
    {
        $this->client->request('GET', '/admin/comptes');

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertResponseRedirects();
    }

    public function test_super_admin_cree_un_compte(): void
    {
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_SUPER_ADMIN));

        $this->client->request('GET', '/admin/comptes/nouveau');
        self::assertResponseIsSuccessful();

        $emailNouveau = 'nouveau-' . uniqid() . '@test.local';

        $this->client->submitForm('Créer le compte', [
            'utilisateur_admin[prenom]'              => 'Nouvel',
            'utilisateur_admin[nom]'                 => 'Agent',
            'utilisateur_admin[email]'               => $emailNouveau,
            'utilisateur_admin[role]'                => RoleUtilisateur::PERSONNEL->value,
            'utilisateur_admin[motDePasse][first]'   => 'MotDePasse12!',
            'utilisateur_admin[motDePasse][second]'  => 'MotDePasse12!',
        ]);

        self::assertResponseRedirects('/admin/comptes');

        // Le compte existe en base avec le bon rôle (services re-récupérés post-requête).
        $repository = static::getContainer()->get(UtilisateurRepository::class);
        $cree = $repository->findOneBy(['email' => $emailNouveau]);

        self::assertInstanceOf(Utilisateur::class, $cree);
        self::assertSame(RoleUtilisateur::PERSONNEL, $cree->getRole());

        // Nettoyage : WebTestCase ne rollback pas, on retire le compte créé.
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->remove($cree);
        $entityManager->flush();
    }

    public function test_creation_refusee_au_personnel(): void
    {
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_PERSONNEL));

        $this->client->request('GET', '/admin/comptes/nouveau');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_creation_refusee_a_l_auditeur(): void
    {
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_AUDITEUR));

        $this->client->request('GET', '/admin/comptes/nouveau');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_super_admin_modifie_le_role_dun_autre_compte(): void
    {
        $cible = $this->creerCompte(RoleUtilisateur::AUDITEUR);

        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_SUPER_ADMIN));

        $this->client->request('GET', '/admin/comptes/' . $cible->getId() . '/modifier');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Enregistrer', [
            'utilisateur_admin[role]' => RoleUtilisateur::PERSONNEL->value,
        ]);

        self::assertResponseRedirects('/admin/comptes');

        self::assertSame(RoleUtilisateur::PERSONNEL, $this->roleEnBase($cible->getEmail()));
    }

    public function test_auto_retrogradation_bloquee_quand_il_reste_un_autre_super_admin(): void
    {
        // Un 2e super-admin → countSuperAdmins() = 2 : la garde « dernier admin »
        // ne s'applique pas, c'est l'anti-soi (Voter CHANGE_ROLE) qui doit bloquer.
        $this->creerCompte(RoleUtilisateur::SUPER_ADMIN);

        $admin = $this->recupererUtilisateur(self::EMAIL_SUPER_ADMIN);
        $this->client->loginUser($admin);

        $this->client->request('GET', '/admin/comptes/' . $admin->getId() . '/modifier');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Enregistrer', [
            'utilisateur_admin[role]' => RoleUtilisateur::PERSONNEL->value,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        self::assertSame(RoleUtilisateur::SUPER_ADMIN, $this->roleEnBase(self::EMAIL_SUPER_ADMIN));
    }

    public function test_retrogradation_du_dernier_super_admin_bloquee(): void
    {
        // Aucun super-admin de test créé → countSuperAdmins() = 1 (fixtures).
        $admin = $this->recupererUtilisateur(self::EMAIL_SUPER_ADMIN);
        $this->client->loginUser($admin);

        $this->client->request('GET', '/admin/comptes/' . $admin->getId() . '/modifier');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Enregistrer', [
            'utilisateur_admin[role]' => RoleUtilisateur::PERSONNEL->value,
        ]);

        // Re-render avec flash error (garde a), pas de redirection ni de flush.
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Vous ne pouvez pas retirer le dernier compte super-administrateur.',
            (string) $this->client->getResponse()->getContent(),
        );

        self::assertSame(RoleUtilisateur::SUPER_ADMIN, $this->roleEnBase(self::EMAIL_SUPER_ADMIN));
    }

    public function test_modification_refusee_au_personnel(): void
    {
        $personnel = $this->recupererUtilisateur(self::EMAIL_PERSONNEL);
        $this->client->loginUser($personnel);

        $this->client->request('GET', '/admin/comptes/' . $personnel->getId() . '/modifier');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_modification_refusee_a_l_auditeur(): void
    {
        $auditeur = $this->recupererUtilisateur(self::EMAIL_AUDITEUR);
        $this->client->loginUser($auditeur);

        $this->client->request('GET', '/admin/comptes/' . $auditeur->getId() . '/modifier');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_creation_auditeur_avec_service_force_le_service_a_null(): void
    {
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_SUPER_ADMIN));
        $serviceId = $this->unService()->getId();
        $email     = 'auditeur-' . uniqid() . self::MARQUEUR_TEST;

        $this->client->request('GET', '/admin/comptes/nouveau');
        $this->client->submitForm('Créer le compte', [
            'utilisateur_admin[prenom]'             => 'Aud',
            'utilisateur_admin[nom]'                => 'Iteur',
            'utilisateur_admin[email]'              => $email,
            'utilisateur_admin[role]'               => RoleUtilisateur::AUDITEUR->value,
            'utilisateur_admin[service]'            => (string) $serviceId,
            'utilisateur_admin[motDePasse][first]'  => 'MotDePasse12!',
            'utilisateur_admin[motDePasse][second]' => 'MotDePasse12!',
        ]);

        self::assertResponseRedirects('/admin/comptes');
        self::assertNull($this->compteEnBase($email)->getService());
    }

    public function test_creation_personnel_conserve_son_service(): void
    {
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_SUPER_ADMIN));
        $serviceId = $this->unService()->getId();
        $email     = 'personnel-' . uniqid() . self::MARQUEUR_TEST;

        $this->client->request('GET', '/admin/comptes/nouveau');
        $this->client->submitForm('Créer le compte', [
            'utilisateur_admin[prenom]'             => 'Per',
            'utilisateur_admin[nom]'                => 'Sonnel',
            'utilisateur_admin[email]'              => $email,
            'utilisateur_admin[role]'               => RoleUtilisateur::PERSONNEL->value,
            'utilisateur_admin[service]'            => (string) $serviceId,
            'utilisateur_admin[motDePasse][first]'  => 'MotDePasse12!',
            'utilisateur_admin[motDePasse][second]' => 'MotDePasse12!',
        ]);

        self::assertResponseRedirects('/admin/comptes');
        $service = $this->compteEnBase($email)->getService();
        self::assertNotNull($service);
        self::assertSame($serviceId, $service->getId());
    }

    public function test_creation_super_admin_conserve_son_service(): void
    {
        // Un super-admin agit aussi comme personnel (role_hierarchy) : il conserve son service.
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_SUPER_ADMIN));
        $serviceId = $this->unService()->getId();
        $email     = 'superadmin-' . uniqid() . self::MARQUEUR_TEST;

        $this->client->request('GET', '/admin/comptes/nouveau');
        $this->client->submitForm('Créer le compte', [
            'utilisateur_admin[prenom]'             => 'Super',
            'utilisateur_admin[nom]'                => 'Admin',
            'utilisateur_admin[email]'              => $email,
            'utilisateur_admin[role]'               => RoleUtilisateur::SUPER_ADMIN->value,
            'utilisateur_admin[service]'            => (string) $serviceId,
            'utilisateur_admin[motDePasse][first]'  => 'MotDePasse12!',
            'utilisateur_admin[motDePasse][second]' => 'MotDePasse12!',
        ]);

        self::assertResponseRedirects('/admin/comptes');
        $service = $this->compteEnBase($email)->getService();
        self::assertNotNull($service);
        self::assertSame($serviceId, $service->getId());
    }

    public function test_edition_vers_auditeur_force_le_service_a_null(): void
    {
        // Cible Personnel AVEC un service, qu'on rétrograde en Auditeur.
        $cible = $this->creerCompte(RoleUtilisateur::PERSONNEL, $this->unService());
        self::assertNotNull($cible->getService());

        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_SUPER_ADMIN));

        $this->client->request('GET', '/admin/comptes/' . $cible->getId() . '/modifier');
        $this->client->submitForm('Enregistrer', [
            'utilisateur_admin[role]' => RoleUtilisateur::AUDITEUR->value,
        ]);

        self::assertResponseRedirects('/admin/comptes');
        self::assertNull($this->compteEnBase($cible->getEmail())->getService());
    }

    /**
     * Compte réellement persisté en base. `clear()` détache les entités managées :
     * sans cela, `findOneBy` relirait l'entité mutée en mémoire par `handleRequest`
     * (mais non flushée) depuis l'identity map de l'EM partagé.
     */
    private function compteEnBase(string $email): Utilisateur
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $compte = static::getContainer()->get(UtilisateurRepository::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(Utilisateur::class, $compte);

        return $compte;
    }

    private function roleEnBase(string $email): RoleUtilisateur
    {
        return $this->compteEnBase($email)->getRole();
    }

    private function unService(): Service
    {
        $service = static::getContainer()->get(ServiceRepository::class)->findActifs()[0] ?? null;
        self::assertInstanceOf(Service::class, $service, 'Aucun service actif en fixtures.');

        return $service;
    }

    private function creerCompte(RoleUtilisateur $role, ?Service $service = null): Utilisateur
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $compte = (new Utilisateur())
            ->setEmail('compte-' . uniqid() . self::MARQUEUR_TEST)
            ->setNom('CibleNom')
            ->setPrenom('CiblePrenom')
            ->setRole($role)
            ->setEstActif(true)
            ->setService($service)
            ->setMotDePasseHash('placeholder-not-real');

        $entityManager->persist($compte);
        $entityManager->flush();

        return $compte;
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
