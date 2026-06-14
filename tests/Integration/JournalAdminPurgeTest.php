<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\JournalAdmin;
use App\Enum\TypeActionJournal;
use App\Repository\JournalAdminRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration de JournalAdminRepository::purgerAvant (DT-15) :
 * seules les entrées antérieures au seuil de conservation sont supprimées,
 * les récentes subsistent.
 *
 * La table est vidée en début de transaction (DELETE rollbacké en tearDown) pour
 * un jeu contrôlé déterministe. `dateAction` est posée par réflexion afin de
 * maîtriser la chronologie. Le marqueur `details` identifie chaque entrée.
 */
final class JournalAdminPurgeTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private JournalAdminRepository $journalRepository;
    private \DateTimeImmutable $maintenant;

    protected function setUp(): void
    {
        self::bootKernel(['environment' => 'test']);
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->journalRepository = $container->get(JournalAdminRepository::class);
        $this->maintenant = new \DateTimeImmutable();

        $this->entityManager->beginTransaction();
        // Jeu contrôlé : on part d'une table vide (rollback en tearDown).
        $this->entityManager->createQuery('DELETE FROM App\Entity\JournalAdmin j')->execute();

        // Anciennes (au-delà des 12 mois de conservation).
        $this->creerEntree('vieux-14', $this->maintenant->modify('-14 months'));
        $this->creerEntree('vieux-13', $this->maintenant->modify('-13 months'));
        // Récentes (dans la fenêtre de conservation).
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

    public function test_purger_avant_supprime_uniquement_les_entrees_expirees(): void
    {
        $seuil = $this->maintenant->modify('-12 months');

        $supprimees = $this->journalRepository->purgerAvant($seuil);

        self::assertSame(2, $supprimees);

        // Un DELETE DQL ne synchronise pas l'identity map : on la vide avant de
        // re-interroger la base pour observer l'état réellement persisté.
        $this->entityManager->clear();

        $restantes = $this->journalRepository->findAll();
        self::assertCount(2, $restantes);

        $marqueurs = array_map(
            static fn (JournalAdmin $entree): string => (string) $entree->getDetails(),
            $restantes,
        );
        sort($marqueurs);
        self::assertSame(['recent-0', 'recent-1'], $marqueurs);
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
