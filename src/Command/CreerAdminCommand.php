<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Validator\ContraintesMotDePasse;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Cree un compte super-administrateur en ligne de commande.
 *
 * Sert au bootstrap du premier administrateur sur un environnement neuf
 * (aucune interface ne permet d en creer un sans deja etre super-admin) et
 * reste utilisable pour ajouter d autres administrateurs. Le mot de passe est
 * saisi de maniere masquee (jamais en argument ni dans l historique) et hashe
 * en argon2id via le password hasher applicatif.
 */
#[AsCommand(
    name: 'app:creer-admin',
    description: 'Cree un compte super-administrateur (bootstrap initial ou ajout).',
)]
final class CreerAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Adresse e-mail de l administrateur')
            ->addOption('nom', null, InputOption::VALUE_REQUIRED, 'Nom de famille')
            ->addOption('prenom', null, InputOption::VALUE_REQUIRED, 'Prenom');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Creation d un super-administrateur');

        $email = strtolower(trim((string) ($input->getOption('email') ?? $io->ask('Adresse e-mail'))));
        if (\count($this->validator->validate($email, [new NotBlank(), new Email()])) > 0) {
            $io->error('Adresse e-mail invalide.');

            return Command::INVALID;
        }

        if (null !== $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => $email])) {
            $io->error(\sprintf('Un utilisateur existe deja avec l email "%s".', $email));

            return Command::INVALID;
        }

        $nom = trim((string) ($input->getOption('nom') ?? $io->ask('Nom')));
        $prenom = trim((string) ($input->getOption('prenom') ?? $io->ask('Prenom')));

        $io->writeln(ContraintesMotDePasse::AIDE);
        $clair = (string) $io->askHidden('Mot de passe');
        if ($clair !== (string) $io->askHidden('Confirmation du mot de passe')) {
            $io->error('Les deux saisies ne correspondent pas.');

            return Command::INVALID;
        }
        $erreursMdp = $this->validator->validate($clair, ContraintesMotDePasse::regles());
        if (\count($erreursMdp) > 0) {
            $io->error('Mot de passe non conforme :');
            foreach ($erreursMdp as $violation) {
                $io->writeln(' - ' . $violation->getMessage());
            }

            return Command::INVALID;
        }

        $admin = new Utilisateur();
        $admin->setEmail($email);
        $admin->setNom($nom);
        $admin->setPrenom($prenom);
        $admin->setRole(RoleUtilisateur::SUPER_ADMIN);

        $erreurs = $this->validator->validate($admin);
        if (\count($erreurs) > 0) {
            $io->error('Donnees invalides :');
            foreach ($erreurs as $violation) {
                $io->writeln(\sprintf(' - %s : %s', $violation->getPropertyPath(), $violation->getMessage()));
            }

            return Command::INVALID;
        }

        $admin->setMotDePasseHash($this->hasher->hashPassword($admin, $clair));
        $this->em->persist($admin);
        $this->em->flush();

        $io->success(\sprintf('Super-administrateur cree : %s (%s %s).', $email, $prenom, $nom));

        return Command::SUCCESS;
    }
}
