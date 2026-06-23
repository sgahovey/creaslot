<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DateFormatterService;
use App\Service\NotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande de diagnostic pour valider la configuration mailer Brevo.
 *
 * Usage :
 *   php bin/console app:email:test destinataire@exemple.fr
 *   php bin/console app:email:test destinataire@exemple.fr --template=emails/custom.html.twig
 */
#[AsCommand(
    name: 'app:email:test',
    description: 'Envoie un email de test via Brevo pour valider la configuration mailer',
)]
final class AppEmailTestCommand extends Command
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly DateFormatterService $dateFormatter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'destinataire',
                InputArgument::REQUIRED,
                'Adresse email du destinataire du test',
            )
            ->addOption(
                'template',
                null,
                InputOption::VALUE_REQUIRED,
                'Template Twig à utiliser pour le contenu de l\'email',
                'emails/test.html.twig',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('CreaSlot — Email Test');

        $destinataire = (string) $input->getArgument('destinataire');
        $template = (string) $input->getOption('template');
        $maintenant = new \DateTimeImmutable();

        $io->writeln(sprintf('  <info>Envoi d\'un email de test à</info> : %s', $destinataire));
        $io->writeln(sprintf('  <info>Template utilisé</info>            : %s', $template));
        $io->newLine();

        $subject = 'Test CreaSlot — ' . $this->dateFormatter->pourSujetEmail($maintenant);

        try {
            $this->notificationService->envoyer(
                $destinataire,
                $subject,
                $template,
                [
                    'date_envoi' => $maintenant,
                ],
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success('Email envoyé avec succès. Vérifiez votre boîte de réception.');

        return Command::SUCCESS;
    }
}
