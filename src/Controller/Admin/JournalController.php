<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\TypeActionJournal;
use App\Repository\JournalAdminRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Consultation du journal d'accountability des actions d'administration (US-5.5).
 *
 * **Lecture seule** : aucune route de mutation — l'immutabilité de la trace est la
 * garantie d'intégrité. Accès super-admin uniquement (défense en profondeur :
 * `#[IsGranted]` + `access_control ^/admin`).
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class JournalController extends AbstractController
{
    private const int ENTREES_PAR_PAGE = 25;

    public function __construct(
        private readonly JournalAdminRepository $journalAdminRepository,
    ) {}

    #[Route('/admin/journal', name: 'app_admin_journal', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $type = TypeActionJournal::tryFrom($request->query->getString('type'));

        $entrees = $this->journalAdminRepository->findPourAdmin($page, self::ENTREES_PAR_PAGE, $type);
        $total   = count($entrees);

        return $this->render('admin/journal/liste.html.twig', [
            'entrees'          => $entrees,
            'page'             => $page,
            'nbPages'          => max(1, (int) ceil($total / self::ENTREES_PAR_PAGE)),
            'total'            => $total,
            'typeSelectionne'  => $type,
            'typesDisponibles' => TypeActionJournal::cases(),
        ]);
    }
}
