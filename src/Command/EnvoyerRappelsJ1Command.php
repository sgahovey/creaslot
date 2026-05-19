<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ReservationRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande planifiée d'envoi des rappels J-1 par email aux Auditeurs (US-4.6).
 *
 * Parcourt les Reservations ACTIVE dont le créneau a lieu demain (timezone
 * Indian/Reunion) et qui n'ont pas encore reçu de rappel email
 * (rappelEnvoyeAt IS NULL). Pour chaque réservation, envoie un email de
 * rappel et marque la réservation comme "rappelée".
 *
 * Exécution prévue : chaque jour à 18h Réunion via cron Linux côté serveur.
 *
 * Configuration cron (à appliquer sur le VPS prod, hors scope code) :
 *   0 18 * * * cd /var/www/creaslot && docker compose exec -T app php bin/console app:envoyer-rappels-j1 >> var/log/cron.log 2>&1
 *
 * Politique de résilience : si l'envoi échoue pour une réservation
 * (exception SMTP, etc.), la commande logue l'erreur et continue avec
 * la réservation suivante (résilience batch). Le rappelEnvoyeAt n'est
 * setté que si l'envoi réussit, garantissant un retry naturel au prochain
 * passage du cron.
 *
 * Idempotence : la query Repository filtre WHERE rappelEnvoyeAt IS NULL,
 * donc une réservation déjà rappelée ne sera pas re-traitée même si la
 * commande est relancée manuellement.
 */
#[AsCommand(
    name: 'app:envoyer-rappels-j1',
    description: 'Envoie les rappels J-1 par email pour les RDV du lendemain (timezone Réunion).',
)]
final class EnvoyerRappelsJ1Command extends Command
{
    public function __construct(
        private readonly ReservationRepository $reservationRepository,
        private readonly NotificationService $notificationService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Plage "demain 00:00 → 23:59" en heure Réunion (timezone applicative).
        $demainDebut = (new \DateTimeImmutable('tomorrow'))->setTime(0, 0, 0);
        $demainFin   = (new \DateTimeImmutable('tomorrow'))->setTime(23, 59, 59);

        $io->title('Envoi des rappels J-1');
        $io->writeln(sprintf(
            'Recherche des réservations ACTIVE pour le %s (Réunion)...',
            $demainDebut->format('d/m/Y'),
        ));

        $reservations = $this->reservationRepository
            ->findActivesPourDemainSansRappel($demainDebut, $demainFin);

        $envoyes = 0;
        $erreurs = 0;

        foreach ($reservations as $reservation) {
            try {
                $this->notificationService->notifierAuditeurRappel($reservation);
                $reservation->setRappelEnvoyeAt(new \DateTimeImmutable());
                $envoyes++;
            } catch (\Throwable $e) {
                $erreurs++;
                $this->logger->error('Echec envoi rappel J-1 (batch)', [
                    'reservation_id' => $reservation->getId(),
                    'exception'      => $e::class,
                    'message'        => $e->getMessage(),
                ]);
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'Rappels J-1 : %d envoyés, %d erreurs.',
            $envoyes,
            $erreurs,
        ));

        return Command::SUCCESS;
    }
}
