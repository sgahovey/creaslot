<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\JournalAdmin;
use App\Enum\TypeActionJournal;
use App\Repository\JournalAdminRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Test de la commande app:purger-journal (DT-15).
 *
 * Pattern identique à JournalAdminPurgeTest : transaction ouverte + DELETE initial
 * en setUp (rollback en tearDown), `dateAction` posée par réflexion pour maîtriser
 * la chronologie. La commande s'exécute dans la même connexion que la transaction,
 * son DELETE est donc lui aussi rollbacké.
 */
final class PurgerJournalCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private JournalAdminRepository $journalRepository;
    private CommandTester $commandTester;
    private \DateTimeImmutable $maintenant;

    protected function setUp(): void
    {
        $kernel = self::bootKernel(['environment' => 'test']);
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->journalRepository = $container->get(JournalAdminRepository::class);
        $this->maintenant = new \DateTimeImmutable();

        $application = new Application($kernel);
        $this->commandTester = new CommandTester($application->find('app:purger-journal'));

        $this->entityManager->beginTransaction();
        $this->entityManager->createQuery('DELETE FROM App\Entity\JournalAdmin j')->execute();

        // Anciennes (au-delà des 12 mois) + récentes (dans la fenêtre).
        $this->creerEntree('vieux-14', $this->maintenant->modify('-14 months'));
        $this->creerEntree('vieux-13', $this->maintenant->modify('-13 months'));
        $this->creerEntree('recent-1', $this->maintenant->modify('-1 month'));
        $this->creerEntree('recent-0', $this->maintenant);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        $this->entityManager->close();
        parent::tearDown();
    }

    public function test_dry_run_ne_supprime_rien_et_annonce_le_compte(): void
    {
        $this->commandTester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertStringContainsString('2 entrées SERAIENT purgées', $this->commandTester->getDisplay());

        $this->entityManager->clear();
        self::assertCount(4, $this->journalRepository->findAll());
    }

    public function test_execution_reelle_purge_uniquement_les_entrees_expirees(): void
    {
        $this->commandTester->execute([]);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertStringContainsString('2 entrées purgées', $this->commandTester->getDisplay());

        $this->entityManager->clear();
        $restantes = array_map(
            static fn (JournalAdmin $entree): string => (string) $entree->getDetails(),
            $this->journalRepository->findAll(),
        );
        sort($restantes);
        self::assertSame(['recent-0', 'recent-1'], $restantes);
    }

    public function test_option_mois_modifie_le_seuil_et_purge_davantage(): void
    {
        // Entrée à -8 mois : conservée par le défaut (12), mais purgée par --mois=6.
        $this->creerEntree('vieux-8', $this->maintenant->modify('-8 months'));
        $this->entityManager->flush();

        $this->commandTester->execute(['--mois' => 6]);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        // -14, -13 et -8 mois → 3 entrées purgées (davantage que les 2 du défaut 12).
        self::assertStringContainsString('3 entrées purgées', $this->commandTester->getDisplay());

        $this->entityManager->clear();
        self::assertCount(2, $this->journalRepository->findAll());
    }

    public function test_option_mois_invalide_retourne_invalid(): void
    {
        $this->commandTester->execute(['--mois' => 0]);

        self::assertSame(Command::INVALID, $this->commandTester->getStatusCode());
        self::assertStringContainsString('--mois', $this->commandTester->getDisplay());

        $this->entityManager->clear();
        self::assertCount(4, $this->journalRepository->findAll());
    }

    private function creerEntree(string $marqueur, \DateTimeImmutable $date): void
    {
        $entree = (new JournalAdmin())
            ->setTypeAction(TypeActionJournal::COMPTE_CREATION)
            ->setActeurId(1)
            ->setActeurLibelle('Acteur Test')
            ->setDetails($marqueur);

        $prop = new \ReflectionProperty(JournalAdmin::class, 'dateAction');
        $prop->setValue($entree, $date);

        $this->entityManager->persist($entree);
    }
}
