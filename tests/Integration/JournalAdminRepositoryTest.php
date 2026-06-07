<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\JournalAdmin;
use App\Enum\TypeActionJournal;
use App\Repository\JournalAdminRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration de JournalAdminRepository::findPourAdmin (US-5.5) :
 * tri (plus récent d'abord), pagination, filtre par type.
 *
 * La table est vidée en début de transaction (DELETE rollbacké en tearDown) pour
 * un jeu contrôlé déterministe. `dateAction` est posée par réflexion afin de
 * maîtriser l'ordre chronologique. Le marqueur `details` identifie chaque entrée.
 */
final class JournalAdminRepositoryTest extends KernelTestCase
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

        // E1 (la plus ancienne) … E3 (la plus récente).
        $this->creerEntree(TypeActionJournal::COMPTE_CREATION, 'e1', $this->maintenant->modify('-2 days'));
        $this->creerEntree(TypeActionJournal::COMPTE_MODIFICATION, 'e2', $this->maintenant->modify('-1 day'));
        $this->creerEntree(TypeActionJournal::COMPTE_CREATION, 'e3', $this->maintenant);
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

    public function test_find_pour_admin_trie_du_plus_recent_au_plus_ancien(): void
    {
        $paginator = $this->journalRepository->findPourAdmin(1);

        self::assertCount(3, $paginator);
        self::assertSame(['e3', 'e2', 'e1'], $this->marqueurs($paginator));
    }

    public function test_find_pour_admin_pagine(): void
    {
        self::assertSame(['e3', 'e2'], $this->marqueurs($this->journalRepository->findPourAdmin(1, 2)));
        self::assertSame(['e1'], $this->marqueurs($this->journalRepository->findPourAdmin(2, 2)));
    }

    public function test_find_pour_admin_filtre_par_type(): void
    {
        $paginator = $this->journalRepository->findPourAdmin(1, 25, TypeActionJournal::COMPTE_CREATION);

        self::assertCount(2, $paginator);
        self::assertSame(['e3', 'e1'], $this->marqueurs($paginator));
    }

    /**
     * @param iterable<JournalAdmin> $paginator
     *
     * @return list<string>
     */
    private function marqueurs(iterable $paginator): array
    {
        return array_values(array_map(
            static fn (JournalAdmin $entree): string => (string) $entree->getDetails(),
            iterator_to_array($paginator),
        ));
    }

    private function creerEntree(TypeActionJournal $type, string $marqueur, \DateTimeImmutable $date): void
    {
        $entree = (new JournalAdmin())
            ->setTypeAction($type)
            ->setActeurId(1)
            ->setActeurLibelle('Acteur Test')
            ->setDetails($marqueur);

        $prop = new \ReflectionProperty(JournalAdmin::class, 'dateAction');
        $prop->setValue($entree, $date);

        $this->entityManager->persist($entree);
    }
}
