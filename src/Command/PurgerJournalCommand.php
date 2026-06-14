<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\JournalAdmin;
use App\Repository\JournalAdminRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande planifiée de purge du journal d'administration (DT-15).
 *
 * Applique la durée de conservation RGPD (limitation de la conservation —
 * art. 5.1.e) : supprime les entrées antérieures à `now − N mois`, où N vaut par
 * défaut {@see JournalAdmin::DUREE_CONSERVATION_MOIS}. La purge est bornée par la
 * seule date (cf. JournalAdminRepository::purgerAvant) : le caractère append-only
 * du journal est préservé, on n'efface que l'expiré.
 *
 * Exécution prévue : périodiquement (ex. mensuel) via cron Linux côté serveur,
 * comme `app:envoyer-rappels-j1`. Configuration cron : cf. docs/cron-purger-journal.md.
 *
 * Mode --dry-run : compte les entrées qui seraient purgées sans rien supprimer,
 * pour auditer le volume avant une suppression irréversible.
 */
#[AsCommand(
    name: 'app:purger-journal',
    description: 'Purge les entrées du journal d\'administration au-delà de la durée de conservation RGPD.',
)]
final class PurgerJournalCommand extends Command
{
    private const string TIMEZONE = 'Indian/Reunion';

    public function __construct(
        private readonly JournalAdminRepository $journalRepository,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'mois',
                null,
                InputOption::VALUE_REQUIRED,
                'Durée de conservation en mois (les entrées plus anciennes sont purgées).',
                JournalAdmin::DUREE_CONSERVATION_MOIS,
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Compte les entrées concernées sans rien supprimer.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $mois = (int) $input->getOption('mois');
        if ($mois < 1) {
            $io->error('L\'option --mois doit être un entier supérieur ou égal à 1.');

            return Command::INVALID;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $seuil = (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))
            ->modify(sprintf('-%d months', $mois));
        $seuilLisible = $seuil->format('d/m/Y');

        $io->title('Purge du journal d\'administration');

        if ($dryRun) {
            $nombre = $this->journalRepository->compterAvant($seuil);
            $this->loguer('dry-run', $nombre, $seuil);
            $io->success(sprintf('%d entrées SERAIENT purgées (antérieures au %s).', $nombre, $seuilLisible));

            return Command::SUCCESS;
        }

        $nombre = $this->journalRepository->purgerAvant($seuil);
        $this->loguer('réel', $nombre, $seuil);
        $io->success(sprintf('%d entrées purgées (antérieures au %s).', $nombre, $seuilLisible));

        return Command::SUCCESS;
    }

    private function loguer(string $mode, int $nombre, \DateTimeImmutable $seuil): void
    {
        $this->logger->info('Purge du journal d\'administration (DT-15)', [
            'mode'       => $mode,
            'nombre'     => $nombre,
            'date_seuil' => $seuil->format(\DateTimeInterface::ATOM),
        ]);
    }
}
