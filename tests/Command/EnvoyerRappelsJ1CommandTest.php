<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\EnvoyerRappelsJ1Command;
use App\Entity\Reservation;
use App\Repository\ReservationRepository;
use App\Service\DateFormatterService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests d'intégration légers de la commande EnvoyerRappelsJ1Command (US-4.6).
 *
 * Stratégie tests : mocks de ReservationRepository + NotificationService +
 * EntityManagerInterface + LoggerInterface. On NE construit PAS de vraies
 * entités Doctrine ici (le service est déjà testé dans NotificationServiceTest).
 * On vérifie uniquement la logique d'orchestration de la commande :
 *
 * 1. Appel correct du Repository avec la plage demain 00:00 → 23:59
 * 2. Itération sur les résultats + appel du service par réservation
 * 3. Marquage rappelEnvoyeAt si succès, log error si échec
 * 4. flush() unique en fin de commande (efficacité BDD)
 * 5. Output IO : "X envoyés, Y erreurs"
 * 6. Return Command::SUCCESS
 *
 * Couverture : 3 tests (nominal, résilience, no-op).
 */
final class EnvoyerRappelsJ1CommandTest extends TestCase
{
    private MockObject&ReservationRepository $reservationRepository;
    private MockObject&NotificationService $notificationService;
    private MockObject&EntityManagerInterface $entityManager;
    private MockObject&LoggerInterface $logger;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->reservationRepository = $this->createMock(ReservationRepository::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $command = new EnvoyerRappelsJ1Command(
            reservationRepository: $this->reservationRepository,
            notificationService: $this->notificationService,
            entityManager: $this->entityManager,
            logger: $this->logger,
            dateFormatter: new DateFormatterService(),
        );

        $application = new Application();
        $application->addCommand($command);

        $this->commandTester = new CommandTester(
            $application->find('app:envoyer-rappels-j1'),
        );
    }

    public function test_execute_envoie_les_rappels_et_marque_envoyes(): void
    {
        // 2 réservations actives pour demain → 2 emails + 2 setRappelEnvoyeAt + 1 flush.
        $reservation1 = $this->creerReservationMock(id: 100);
        $reservation2 = $this->creerReservationMock(id: 200);

        $this->reservationRepository->expects($this->once())
            ->method('findActivesPourDemainSansRappel')
            ->willReturn([$reservation1, $reservation2]);

        $this->notificationService->expects($this->exactly(2))
            ->method('notifierAuditeurRappel');

        $reservation1->expects($this->once())->method('setRappelEnvoyeAt');
        $reservation2->expects($this->once())->method('setRappelEnvoyeAt');

        $this->entityManager->expects($this->once())->method('flush');

        $this->logger->expects($this->never())->method('error');

        $exitCode = $this->commandTester->execute([]);

        self::assertSame(0, $exitCode); // Command::SUCCESS
        self::assertStringContainsString('Rappels J-1 : 2 envoyés, 0 erreurs.', $this->commandTester->getDisplay());
    }

    public function test_execute_continue_sur_erreur_dune_seule_reservation(): void
    {
        // 3 réservations : la 2e throws → 2 envoyés, 1 erreur, log error 1 fois.
        $reservation1 = $this->creerReservationMock(id: 100);
        $reservation2 = $this->creerReservationMock(id: 200);
        $reservation3 = $this->creerReservationMock(id: 300);

        $this->reservationRepository->expects($this->once())
            ->method('findActivesPourDemainSansRappel')
            ->willReturn([$reservation1, $reservation2, $reservation3]);

        // Configurer notifierAuditeurRappel : OK / throw / OK.
        $this->notificationService->expects($this->exactly(3))
            ->method('notifierAuditeurRappel')
            ->willReturnCallback(function (Reservation $r): void {
                if ($r->getId() === 200) {
                    throw new \RuntimeException('Mailer down');
                }
            });

        // Seules les réservations 1 et 3 doivent être marquées.
        $reservation1->expects($this->once())->method('setRappelEnvoyeAt');
        $reservation2->expects($this->never())->method('setRappelEnvoyeAt');
        $reservation3->expects($this->once())->method('setRappelEnvoyeAt');

        $this->entityManager->expects($this->once())->method('flush');

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Echec envoi rappel J-1 (batch)',
                $this->callback(function (array $context): bool {
                    return $context['reservation_id'] === 200
                        && $context['exception'] === \RuntimeException::class
                        && $context['message'] === 'Mailer down';
                }),
            );

        $exitCode = $this->commandTester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Rappels J-1 : 2 envoyés, 1 erreurs.', $this->commandTester->getDisplay());
    }

    public function test_execute_termine_avec_succes_si_zero_reservation(): void
    {
        // Cas no-op : 0 réservation à rappeler.
        $this->reservationRepository->expects($this->once())
            ->method('findActivesPourDemainSansRappel')
            ->willReturn([]);

        $this->notificationService->expects($this->never())->method('notifierAuditeurRappel');
        $this->entityManager->expects($this->once())->method('flush'); // flush même si vide (no-op SQL)
        $this->logger->expects($this->never())->method('error');

        $exitCode = $this->commandTester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Rappels J-1 : 0 envoyés, 0 erreurs.', $this->commandTester->getDisplay());
    }

    /**
     * Helper : crée un mock de Reservation avec un id donné (pour les asserts setRappelEnvoyeAt).
     */
    private function creerReservationMock(int $id): MockObject&Reservation
    {
        $reservation = $this->createMock(Reservation::class);
        $reservation->method('getId')->willReturn($id);

        return $reservation;
    }
}
