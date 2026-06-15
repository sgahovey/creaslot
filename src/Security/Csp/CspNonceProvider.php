<?php

declare(strict_types=1);

namespace App\Security\Csp;

/**
 * Fournit un nonce CSP unique par requête (US-9.2).
 *
 * Le nonce est généré paresseusement à la première demande puis mémoïsé : le même
 * service (donc le même nonce) est partagé entre l'extension Twig — qui l'insère sur
 * les <script> inline — et le listener qui pose l'en-tête Content-Security-Policy.
 * En PHP-FPM le conteneur est recréé à chaque requête, garantissant un nonce distinct
 * par requête sans gestion d'état supplémentaire.
 */
final class CspNonceProvider
{
    private ?string $nonce = null;

    public function getNonce(): string
    {
        return $this->nonce ??= base64_encode(random_bytes(16));
    }
}
