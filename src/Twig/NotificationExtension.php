<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Utilisateur;
use App\Repository\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expose le nombre de notifications non lues de l'utilisateur courant (US-4.7),
 * pour le badge du menu (header).
 *
 * Lazy par nature : la fonction `notifications_non_lues()` n'exécute le COUNT
 * que lorsqu'un template l'appelle (le header pour les Auditeurs), pas à chaque
 * requête Symfony (mitigation R3). L'index composite (id_destinataire, lu)
 * garantit un COUNT rapide.
 */
final class NotificationExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly NotificationRepository $notificationRepository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('notifications_non_lues', $this->compterNonLues(...)),
        ];
    }

    /**
     * Nombre de notifications non lues de l'utilisateur connecté.
     * Retourne 0 si personne n'est connecté ou si l'utilisateur n'est pas un Auditeur.
     */
    public function compterNonLues(): int
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return 0;
        }

        return $this->notificationRepository->countNonLues($utilisateur);
    }
}
