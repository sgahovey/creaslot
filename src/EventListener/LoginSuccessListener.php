<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

#[AsEventListener(event: LoginSuccessEvent::class)]
final class LoginSuccessListener
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $utilisateur = $event->getAuthenticatedToken()->getUser();

        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $utilisateur->setDerniereConnexion(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->logger->info('Connexion réussie', ['user_id' => $utilisateur->getId()]);
    }
}
