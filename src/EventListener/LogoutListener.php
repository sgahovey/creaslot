<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Utilisateur;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LogoutEvent;

#[AsEventListener(event: LogoutEvent::class)]
final class LogoutListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(LogoutEvent $event): void
    {
        $utilisateur = $event->getToken()?->getUser();

        if ($utilisateur instanceof Utilisateur) {
            $this->logger->info('Déconnexion', ['user_id' => $utilisateur->getId()]);
        }

        $event->getRequest()->getSession()->getFlashBag()->add(
            'info',
            'Vous avez été déconnecté.',
        );
    }
}
