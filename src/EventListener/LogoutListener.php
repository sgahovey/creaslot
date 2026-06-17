<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Utilisateur;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

#[AsEventListener(event: LogoutEvent::class)]
final class LogoutListener
{
    public function __construct(
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    public function __invoke(LogoutEvent $event): void
    {
        $utilisateur = $event->getToken()?->getUser();

        if ($utilisateur instanceof Utilisateur) {
            $this->securityLogger->info('Déconnexion', ['user_id' => $utilisateur->getId()]);
        }

        // getFlashBag() n'est pas déclaré par SessionInterface (Symfony 8) : on
        // restreint à la session porteuse de flash bag (la session concrète l'est).
        $session = $event->getRequest()->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('info', 'Vous avez été déconnecté.');
        }
    }
}
