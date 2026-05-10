<?php

declare(strict_types=1);

namespace App\Controller\Auditeur;

use App\Entity\Utilisateur;
use App\Repository\CreneauRepository;
use App\Repository\ServiceRepository;
use App\Repository\TypeRdvRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_AUDITEUR')]
class CreneauDisponibleController extends AbstractController
{
    public function __construct(
        private readonly CreneauRepository $creneauRepository,
        private readonly ServiceRepository $serviceRepository,
        private readonly TypeRdvRepository $typeRdvRepository,
        private readonly LoggerInterface   $logger,
    ) {}

    #[Route('/creneaux-disponibles', name: 'app_creneaux_disponibles', methods: ['GET'])]
    public function liste(Request $request): Response
    {
        $typeRdvId = $this->parseIntParam($request->query->getString('type', ''));
        $serviceId = $this->parseIntParam($request->query->getString('service', ''));
        $dateStr   = $request->query->getString('date', '');
        $date      = $this->parseDate($dateStr);
        $page      = max(1, $this->parseIntParam($request->query->getString('page', '')) ?? 1);

        $paginator = $this->creneauRepository->findDisponibles($typeRdvId, $serviceId, $date, $page);
        $total     = count($paginator);

        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();
        $this->logger->info('Recherche créneaux disponibles', [
            'user_id' => $utilisateur->getId(),
            'filtres' => ['type' => $typeRdvId, 'service' => $serviceId, 'date' => $dateStr],
        ]);

        return $this->render('auditeur/creneau/disponibles.html.twig', [
            'creneaux'  => $paginator,
            'total'     => $total,
            'page'      => $page,
            'nbPages'   => max(1, (int) ceil($total / 12)),
            'typesRdv'  => $this->typeRdvRepository->findActifs(),
            'services'  => $this->serviceRepository->findActifs(),
            'filtres'   => ['type' => $typeRdvId, 'service' => $serviceId, 'date' => $dateStr],
        ]);
    }

    private function parseDate(string $dateStr): ?\DateTimeImmutable
    {
        if ($dateStr === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $dateStr);

        return $date !== false ? $date : null;
    }

    private function parseIntParam(string $value): ?int
    {
        $trimmed = trim($value);

        if ($trimmed === '' || !ctype_digit($trimmed)) {
            return null;
        }

        return (int) $trimmed;
    }
}
