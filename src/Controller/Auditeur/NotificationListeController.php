<?php

declare(strict_types=1);

namespace App\Controller\Auditeur;

use App\Entity\Utilisateur;
use App\Repository\NotificationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Liste des notifications in-app de l'Auditeur courant (US-4.7).
 *
 * Auto-lu (Q-US47-D1) : à l'ouverture, toutes les notifications non lues de
 * l'Auditeur sont marquées comme lues.
 *
 * Ordre critique — marquer-lues PUIS lister :
 * marquerToutesLues() est un UPDATE DQL en masse qui contourne l'UnitOfWork.
 * Lister APRÈS garantit que les entités hydratées reflètent l'état lu=true.
 * Inversé, le template afficherait un état lu=false périmé.
 *
 * Sécurité : pas de Voter — le filtrage par destinataire dans le repository
 * garantit que l'Auditeur ne voit QUE ses propres notifications.
 */
#[IsGranted('ROLE_AUDITEUR')]
final class NotificationListeController extends AbstractController
{
    private const LIMIT_PAR_PAGE = 10;

    public function __construct(
        private readonly NotificationRepository $notificationRepository,
    ) {}

    #[Route('/mes-notifications', name: 'app_mes_notifications', methods: ['GET'])]
    public function liste(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));

        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        // Ordre critique : marquer-lues (UPDATE SQL, bypass UoW) PUIS lister.
        $this->notificationRepository->marquerToutesLues($utilisateur);

        $paginator = $this->notificationRepository->findByDestinatairePaginated($utilisateur, $page);
        $total     = count($paginator);

        return $this->render('auditeur/notification/liste.html.twig', [
            'notifications' => $paginator,
            'total'         => $total,
            'page'          => $page,
            'nbPages'       => max(1, (int) ceil($total / self::LIMIT_PAR_PAGE)),
        ]);
    }
}
