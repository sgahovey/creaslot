<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Security\Csp\CspNonceProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Pose l'en-tête Content-Security-Policy sur les réponses HTML (US-9.2, OWASP A05).
 *
 * Utilise le MÊME CspNonceProvider que l'extension Twig (autowiring -> instance
 * partagée par requête), donc le `'nonce-…'` de l'en-tête correspond exactement aux
 * <script> inline nonce-és dans les templates.
 *
 * `style-src 'unsafe-inline'` est conservé : les attributs `style=` inline (charte)
 * ne sont couverts ni par nonce ni par hash. Aucun `'unsafe-eval'` : les librairies
 * front (Bootstrap, Stimulus, Turbo, Chart.js, FullCalendar) n'en utilisent pas.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final class CspResponseListener
{
    public function __construct(
        private readonly CspNonceProvider $nonceProvider,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // En dev, ne pas contraindre la web debug toolbar (scripts/styles du profiler).
        if ($this->environment === 'dev') {
            return;
        }

        $response = $event->getResponse();

        // Ne s'applique qu'aux pages HTML (jamais sur le JSON de l'API). À kernel.response,
        // une réponse HTML issue de render() n'a pas encore son Content-Type (posé par
        // Response::prepare()) -> on retient text/html par défaut. Les JsonResponse fixent
        // leur Content-Type dès la construction, donc elles restent bien exclues.
        $contentType = (string) $response->headers->get('Content-Type', 'text/html');
        if (!str_contains($contentType, 'text/html')) {
            return;
        }

        // Ne pas écraser une CSP éventuellement déjà posée.
        if ($response->headers->has('Content-Security-Policy')) {
            return;
        }

        $nonce = $this->nonceProvider->getNonce();
        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'none'",
            "form-action 'self'",
        ]));
    }
}
