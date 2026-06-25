<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\Traits\JsonSansCacheTrait;
use App\Entity\Utilisateur;
use App\Repository\CreneauRepository;
use App\Service\CreneauCalendarSerializer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api')]
#[IsGranted('ROLE_PERSONNEL')]
final class CreneauApiController extends AbstractController
{
    use JsonSansCacheTrait;

    public function __construct(
        private readonly CreneauRepository $creneauRepository,
        private readonly CreneauCalendarSerializer $serializer,
    ) {
    }

    #[Route('/creneaux/next-reserved', name: 'api_creneaux_next_reserved', methods: ['GET'])]
    public function nextReserved(): JsonResponse
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        $creneau = $this->creneauRepository->findNextReservedCreneau($utilisateur);

        return $this->jsonSansCache([
            'date' => $creneau === null ? null : $creneau->getDateDebut()->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/creneaux', name: 'api_creneaux_personnel', methods: ['GET'])]
    public function listForCalendar(Request $request): JsonResponse
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        $debutRaw = $request->query->getString('start');
        $finRaw = $request->query->getString('end');

        if ($debutRaw === '' || $finRaw === '') {
            return $this->jsonSansCache([]);
        }

        try {
            $debutPlage = new \DateTimeImmutable($debutRaw);
            $finPlage = new \DateTimeImmutable($finRaw);
        } catch (\Throwable) {
            return $this->jsonSansCache([], Response::HTTP_BAD_REQUEST);
        }

        $reserveOnly = $request->query->getBoolean('reserve_only');

        $creneaux = $this->creneauRepository->findByPersonnelInDateRange(
            $utilisateur,
            $debutPlage,
            $finPlage,
            $reserveOnly,
        );

        return $this->jsonSansCache($this->serializer->toCalendarEvents($creneaux));
    }
}
