<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Test de la commande app:creer-admin (US-9.3).
 *
 * Pattern identique à PurgerJournalCommandTest : transaction ouverte en setUp,
 * rollback en tearDown. La commande s'exécute dans la même connexion, son
 * persist/flush est donc lui aussi annulé. L'e-mail de test est purgé en amont
 * pour neutraliser tout résidu d'un jeu de données.
 */
final class CreerAdminCommandTest extends KernelTestCase
{
    private const string EMAIL_TEST = 'admin-test@example.com';
    private const string MDP_CONFORME = 'MotDePasse123!';

    private EntityManagerInterface $entityManager;
    private UtilisateurRepository $utilisateurRepository;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel(['environment' => 'test']);
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->utilisateurRepository = $container->get(UtilisateurRepository::class);

        $application = new Application($kernel);
        $this->commandTester = new CommandTester($application->find('app:creer-admin'));

        $this->entityManager->beginTransaction();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Utilisateur u WHERE u.email = :email')
            ->setParameter('email', self::EMAIL_TEST)
            ->execute();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        $this->entityManager->close();
        parent::tearDown();
    }

    public function test_creation_reussie_cree_un_super_admin_avec_un_hash(): void
    {
        $this->commandTester->setInputs(['Dupont', 'Marie', self::MDP_CONFORME, self::MDP_CONFORME]);
        $this->commandTester->execute(['--email' => self::EMAIL_TEST]);

        $this->commandTester->assertCommandIsSuccessful();

        $this->entityManager->clear();
        $admin = $this->utilisateurRepository->findOneBy(['email' => self::EMAIL_TEST]);

        self::assertNotNull($admin);
        self::assertSame(RoleUtilisateur::SUPER_ADMIN, $admin->getRole());
        self::assertNotSame('', $admin->getMotDePasseHash());
    }

    public function test_email_deja_existant_est_refuse_sans_doublon(): void
    {
        $existant = (new Utilisateur())
            ->setEmail(self::EMAIL_TEST)
            ->setNom('Existant')
            ->setPrenom('Jean')
            ->setRole(RoleUtilisateur::PERSONNEL);
        $existant->setMotDePasseHash('hash-bidon');
        $this->entityManager->persist($existant);
        $this->entityManager->flush();

        $this->commandTester->execute(['--email' => self::EMAIL_TEST]);

        self::assertSame(Command::INVALID, $this->commandTester->getStatusCode());

        $this->entityManager->clear();
        self::assertCount(1, $this->utilisateurRepository->findBy(['email' => self::EMAIL_TEST]));
    }

    public function test_mot_de_passe_trop_faible_est_refuse_sans_creation(): void
    {
        $this->commandTester->setInputs(['Dupont', 'Marie', 'court', 'court']);
        $this->commandTester->execute(['--email' => self::EMAIL_TEST]);

        self::assertSame(Command::INVALID, $this->commandTester->getStatusCode());

        $this->entityManager->clear();
        self::assertNull($this->utilisateurRepository->findOneBy(['email' => self::EMAIL_TEST]));
    }
}
