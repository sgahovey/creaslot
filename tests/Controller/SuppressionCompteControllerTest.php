<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Creneau;
use App\Entity\Reservation;
use App\Entity\TypeRdv;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Test fonctionnel de la suppression self-service par anonymisation (US-12.3, RGPD art. 17).
 *
 * Ces tests MUTENT la BDD ; comptes jetables tracés par id (l'email est réécrit à
 * l'anonymisation, donc nettoyage par id en tearDown). Couvrent l'accès (anonyme,
 * super-admin), le cas nominal (anonymisation + logout) et le blocage sur engagements.
 */
final class SuppressionCompteControllerTest extends WebTestCase
{
    private const string MOT_DE_PASSE = 'MotDePasseValide!2026';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $hasher;

    /** @var list<int> */
    private array $idsCrees = [];

    protected function setUp(): void
    {
        $this->client = static::createClient(['environment' => 'test']);
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
    }

    protected function tearDown(): void
    {
        if ($this->idsCrees !== []) {
            $connection = $this->entityManager->getConnection();
            $this->entityManager->createQuery('DELETE FROM App\Entity\Reservation r WHERE IDENTITY(r.utilisateur) IN (:ids)')
                ->setParameter('ids', $this->idsCrees)->execute();
            $this->entityManager->createQuery('DELETE FROM App\Entity\Creneau c WHERE IDENTITY(c.utilisateur) IN (:ids)')
                ->setParameter('ids', $this->idsCrees)->execute();
            $this->entityManager->createQuery('DELETE FROM App\Entity\JournalAdmin j WHERE j.acteurId IN (:ids)')
                ->setParameter('ids', $this->idsCrees)->execute();
            $connection->executeStatement('DELETE FROM historique_utilisateur WHERE utilisateur_id IN (?)', [$this->idsCrees], [ArrayParameterType::INTEGER]);
            $this->entityManager->createQuery('DELETE FROM App\Entity\Utilisateur u WHERE u.id IN (:ids)')
                ->setParameter('ids', $this->idsCrees)->execute();
        }

        parent::tearDown();
    }

    public function test_acces_anonyme_redirige_vers_la_connexion(): void
    {
        $this->client->request('GET', '/mon-profil/supprimer');

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertResponseRedirects();
    }

    public function test_super_admin_ne_peut_pas_acceder(): void
    {
        $this->client->loginUser($this->creerUtilisateur(RoleUtilisateur::SUPER_ADMIN));

        $this->client->request('GET', '/mon-profil/supprimer');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_auditeur_sans_engagement_voit_le_formulaire(): void
    {
        $this->client->loginUser($this->creerUtilisateur(RoleUtilisateur::AUDITEUR));

        $this->client->request('GET', '/mon-profil/supprimer');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Confirmer la suppression', (string) $this->client->getResponse()->getContent());
    }

    public function test_suppression_nominale_anonymise_et_deconnecte(): void
    {
        $auditeur = $this->creerUtilisateur(RoleUtilisateur::AUDITEUR);
        $id = (int) $auditeur->getId();
        $this->client->loginUser($auditeur);

        $this->client->request('GET', '/mon-profil/supprimer');
        $this->client->submitForm('Supprimer définitivement mon compte', [
            'suppression_compte[confirmation]' => '1',
        ]);

        self::assertResponseRedirects('/deconnexion');

        $recharge = $this->rechargerUtilisateur($id);
        self::assertSame(sprintf('anonymise-%d@creaslot.local', $id), $recharge->getEmail());
        self::assertFalse($recharge->isEstActif());
    }

    public function test_confirmation_non_cochee_est_refusee(): void
    {
        $this->client->loginUser($this->creerUtilisateur(RoleUtilisateur::AUDITEUR));

        $this->client->request('GET', '/mon-profil/supprimer');
        // Case laissée décochée (champ absent du payload) : la contrainte IsTrue échoue.
        $this->client->submitForm('Supprimer définitivement mon compte', []);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_blocage_si_engagement_futur_ne_modifie_rien(): void
    {
        $auditeur = $this->creerUtilisateur(RoleUtilisateur::AUDITEUR);
        $id = (int) $auditeur->getId();
        $this->client->loginUser($auditeur);

        // Formulaire chargé alors qu'aucun engagement n'existe encore (jeton CSRF valide).
        $this->client->request('GET', '/mon-profil/supprimer');

        // Un engagement futur apparaît ensuite : le re-check du service doit bloquer le POST.
        $personnel = $this->creerUtilisateur(RoleUtilisateur::PERSONNEL);
        $this->creerReservationActive($auditeur, $this->creerCreneauFutur($personnel));

        $this->client->submitForm('Supprimer définitivement mon compte', [
            'suppression_compte[confirmation]' => '1',
        ]);

        self::assertResponseRedirects('/mon-profil/supprimer');

        $recharge = $this->rechargerUtilisateur($id);
        self::assertTrue($recharge->isEstActif());
        self::assertSame('Anonyme', $recharge->getNom());
    }

    private function creerUtilisateur(RoleUtilisateur $role): Utilisateur
    {
        $utilisateur = (new Utilisateur())
            ->setEmail('suppression-' . uniqid() . '@test.local')
            ->setNom('Anonyme')
            ->setPrenom('Test')
            ->setRole($role)
            ->setEstActif(true);
        $utilisateur->setMotDePasseHash($this->hasher->hashPassword($utilisateur, self::MOT_DE_PASSE));

        $this->entityManager->persist($utilisateur);
        $this->entityManager->flush();

        $this->idsCrees[] = (int) $utilisateur->getId();

        return $utilisateur;
    }

    private function creerCreneauFutur(Utilisateur $personnel): Creneau
    {
        $creneau = (new Creneau())
            ->setUtilisateur($personnel)
            ->setTypeRdv($this->unTypeRdv())
            ->setDateDebut(new \DateTimeImmutable('+2 days'))
            ->setDateFin(new \DateTimeImmutable('+2 days +1 hour'))
            ->setEstActif(true);

        $this->entityManager->persist($creneau);
        $this->entityManager->flush();

        return $creneau;
    }

    private function creerReservationActive(Utilisateur $auditeur, Creneau $creneau): void
    {
        $reservation = (new Reservation())
            ->setUtilisateur($auditeur)
            ->setCreneau($creneau);

        $this->entityManager->persist($reservation);
        $this->entityManager->flush();
    }

    private function unTypeRdv(): TypeRdv
    {
        $typeRdv = $this->entityManager->getRepository(TypeRdv::class)->findOneBy([]);
        self::assertInstanceOf(TypeRdv::class, $typeRdv, 'Aucun TypeRdv en fixtures.');

        return $typeRdv;
    }

    private function rechargerUtilisateur(int $id): Utilisateur
    {
        $this->entityManager->clear();
        $utilisateur = $this->entityManager->getRepository(Utilisateur::class)->find($id);
        self::assertInstanceOf(Utilisateur::class, $utilisateur);

        return $utilisateur;
    }
}
