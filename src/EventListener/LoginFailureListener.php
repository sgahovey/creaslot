<?php

declare(strict_types=1);

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

#[AsEventListener(event: LoginFailureEvent::class)]
final class LoginFailureListener
{
    public function __construct(
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    public function __invoke(LoginFailureEvent $event): void
    {
        $email = $event->getRequest()->request->get('email', '');
        $exception = $event->getException();

        if ($exception instanceof DisabledException) {
            // Compte existant mais désactivé — niveau NOTICE (non problématique)
            $this->securityLogger->notice(
                'Tentative de connexion sur compte désactivé',
                ['email' => $email],
            );

            return;
        }

        if ($exception instanceof TooManyLoginAttemptsAuthenticationException) {
            // Plafonnement atteint : le firewall a compté cinq tentatives sur la
            // fenêtre (login_throttling, security.yaml) et refuse désormais la
            // connexion, mot de passe correct compris. Ce n'est pas un échec
            // ordinaire mais un blocage, d'où le niveau ERROR : il doit ressortir
            // du bruit des fautes de frappe (OWASP A09).
            $this->securityLogger->error(
                'Connexion bloquée après plafonnement des tentatives',
                ['email' => $email],
            );

            return;
        }

        // Mauvais identifiants ou autre échec — niveau WARNING
        $this->securityLogger->warning(
            'Tentative de connexion échouée',
            ['email' => $email],
        );
    }
}
