<?php

declare(strict_types=1);

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

#[AsEventListener(event: LoginFailureEvent::class)]
final class LoginFailureListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(LoginFailureEvent $event): void
    {
        $email     = $event->getRequest()->request->get('email', '');
        $exception = $event->getException();

        if ($exception instanceof DisabledException) {
            // Compte existant mais désactivé — niveau NOTICE (non problématique)
            $this->logger->notice(
                'Tentative de connexion sur compte désactivé',
                ['email' => $email],
            );

            return;
        }

        // Mauvais identifiants ou autre échec — niveau WARNING
        $this->logger->warning(
            'Tentative de connexion échouée',
            ['email' => $email],
        );
    }
}
