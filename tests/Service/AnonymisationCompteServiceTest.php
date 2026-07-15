<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Creneau;
use App\Entity\JournalAdmin;
use App\Entity\Reservation;
use App\Entity\TypeRdv;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Enum\TypeActionJournal;
use App\Exception\EngagementsFutursException;
use App\Service\AnonymisationCompteService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Test d'intégration du droit à l'effacement par anonymisation (US-12.3, RGPD art. 17).
 *
 * Ces tests MUTENT la BDD ; on travaille sur des comptes jetables tracés par id (créés
 * en setUp, supprimés en tearDown par id — l'email étant réécrit à l'anonymisation, on
 * ne peut pas nettoyer par marqueur d'email). On couvre le cas nominal, la purge des
 * lignes PII de historique_utilisateur (trigger US-12.1), et les blocages (engagements).
 */
final class AnonymisationCompteServiceTest extends KernelTestCase
{
    private const string MOT_DE_PASSE = 'MotDePasseValide!2026';

    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $hasher;
    private AnonymisationCompteService $service;

    /** @var list<int> Identifiants des comptes créés, à purger en tearDown. */
    private array $idsCrees = [];

    protected function setUp(): void
    {
        self::bootKernel(['environment' => 'test']);

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->service = static::getContainer()->get(AnonymisationCompteService::class);
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
            $connection->executeStatement('DELETE FROM historique_utilisateur WHERE utilisateur_id IN (?)', [$this->idsCrees], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
            $this->entityManager->createQuery('DELETE FROM App\Entity\Utilisateur u WHERE u.id IN (:ids)')
                ->setParameter('ids', $this->idsCrees)->execute();
        }

        parent::tearDown();
    }

    public function test_anonymise_neutralise_les_donnees_personnelles(): void
    {
        $auditeur = $this->creerUtilisateur(RoleUtilisateur::AUDITEUR);
        $id = (int) $auditeur->getId();

        $this->service->anonymiser($auditeur);

        $recharge = $this->rechargerUtilisateur($id);
        self::assertSame(sprintf('anonymise-%d@creaslot.local', $id), $recharge->getEmail());
        self::assertSame('Anonymisé', $recharge->getNom());
        self::assertSame('Anonymisé', $recharge->getPrenom());
        self::assertFalse($recharge->isEstActif());
        self::assertFalse(
            $this->hasher->isPasswordValid($recharge, self::MOT_DE_PASSE),
            "L'ancien mot de passe ne doit plus fonctionner.",
        );
    }

    public function test_anonymise_trace_l_action_sans_donnee_personnelle(): void
    {
        $auditeur = $this->creerUtilisateur(RoleUtilisateur::AUDITEUR);
        $id = (int) $auditeur->getId();

        $this->service->anonymiser($auditeur);
        $this->entityManager->clear();

        $entrees = $this->entityManager->getRepository(JournalAdmin::class)
            ->findBy(['cibleId' => $id, 'typeAction' => TypeActionJournal::COMPTE_ANONYMISATION]);

        self::assertCount(1, $entrees);
        // La trace fige des libellés déjà anonymisés : aucune identité réelle conservée.
        self::assertSame('Anonymisé Anonymisé', $entrees[0]->getActeurLibelle());
        self::assertSame('Anonymisé Anonymisé', $entrees[0]->getCibleLibelle());
    }

    public function test_anonymise_purge_les_lignes_pii_de_historique(): void
    {
        $auditeur = $this->creerUtilisateur(RoleUtilisateur::AUDITEUR);
        $id = (int) $auditeur->getId();

        // Un changement préalable de nom fait écrire au trigger une ligne PII 'nom'.
        $auditeur->setNom('AncienNom');
        $this->entityManager->flush();

        $this->service->anonymiser($auditeur);

        $champs = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT champ_modifie FROM historique_utilisateur WHERE utilisateur_id = :id',
            ['id' => $id],
        );

        // Effacement complet : aucune ligne nominative ne subsiste.
        self::assertNotContains('email', $champs);
        self::assertNotContains('nom', $champs);
        self::assertNotContains('prenom', $champs);
        // Les lignes non personnelles sont conservées (accountability) : est_actif a basculé.
        self::assertContains('est_actif', $champs);
    }

    public function test_bloque_si_reservation_active_a_venir(): void
    {
        $auditeur = $this->creerUtilisateur(RoleUtilisateur::AUDITEUR);
        $personnel = $this->creerUtilisateur(RoleUtilisateur::PERSONNEL);
        $creneau = $this->creerCreneauFutur($personnel);
        $this->creerReservationActive($auditeur, $creneau);

        $id = (int) $auditeur->getId();

        try {
            $this->service->anonymiser($auditeur);
            self::fail('Une EngagementsFutursException était attendue.');
        } catch (EngagementsFutursException) {
            // attendu
        }

        $recharge = $this->rechargerUtilisateur($id);
        self::assertTrue($recharge->isEstActif());
        self::assertSame('Anonyme', $recharge->getNom());
    }

    public function test_bloque_si_creneau_futur_propose(): void
    {
        $personnel = $this->creerUtilisateur(RoleUtilisateur::PERSONNEL);
        $this->creerCreneauFutur($personnel);

        $id = (int) $personnel->getId();

        try {
            $this->service->anonymiser($personnel);
            self::fail('Une EngagementsFutursException était attendue.');
        } catch (EngagementsFutursException) {
            // attendu
        }

        $recharge = $this->rechargerUtilisateur($id);
        self::assertTrue($recharge->isEstActif());
    }

    private function creerUtilisateur(RoleUtilisateur $role): Utilisateur
    {
        $utilisateur = (new Utilisateur())
            ->setEmail('anonymisation-' . uniqid() . '@test.local')
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

    private function creerReservationActive(Utilisateur $auditeur, Creneau $creneau): Reservation
    {
        $reservation = (new Reservation())
            ->setUtilisateur($auditeur)
            ->setCreneau($creneau);

        $this->entityManager->persist($reservation);
        $this->entityManager->flush();

        return $reservation;
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
