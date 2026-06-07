<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Enum\TypeActionJournal;
use App\Service\ExportDonneesPersonnellesService;
use App\Service\JournalAdminService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Export des données personnelles (RGPD, US-5.6).
 *
 * Contrôleur transverse (hors `Admin/`) : il porte la voie super-admin (export
 * d'un compte sur demande d'accès, journalisée) et, à terme, la voie self-service.
 * La sécurité est appliquée au niveau de chaque méthode.
 */
final class ExportController extends AbstractController
{
    public function __construct(
        private readonly ExportDonneesPersonnellesService $exportService,
        private readonly JournalAdminService $journalAdminService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/admin/comptes/{id}/export', name: 'app_admin_compte_export', methods: ['GET'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function exporter(Utilisateur $compte): Response
    {
        $donnees = $this->exportService->exporter($compte);

        // Action en lecture pure côté compte → on enregistre et committe ici la trace.
        /** @var Utilisateur $administrateur */
        $administrateur = $this->getUser();
        $this->journalAdminService->enregistrer(TypeActionJournal::COMPTE_EXPORT, $administrateur, $compte);
        $this->entityManager->flush();

        return $this->telechargerJson($donnees, 'donnees-' . $compte->getId());
    }

    #[Route('/admin/comptes/{id}/donnees', name: 'app_admin_compte_donnees', methods: ['GET'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function donnees(Utilisateur $compte): Response
    {
        $donnees = $this->exportService->exporter($compte);

        // Consultation à l'écran = Monolog only. La journalisation accountability
        // (COMPTE_EXPORT) reste réservée au téléchargement JSON (route /export).
        /** @var Utilisateur $administrateur */
        $administrateur = $this->getUser();
        $this->logger->info("Consultation des données personnelles d'un compte", [
            'cible_id' => $compte->getId(),
            'admin_id' => $administrateur->getId(),
        ]);

        return $this->render('admin/compte/donnees.html.twig', [
            'donnees' => $donnees,
            'compte'  => $compte,
        ]);
    }

    #[Route('/mes-donnees', name: 'app_mes_donnees', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function mesDonnees(): Response
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        // Consultation à l'écran de ses propres données (même tableau minimisé que
        // l'export, source unique). Lecture pure : pas de journal, pas de log.
        return $this->render('export/mes_donnees.html.twig', [
            'donnees' => $this->exportService->exporter($utilisateur),
        ]);
    }

    #[Route('/mes-donnees/export', name: 'app_mes_donnees_export', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function exporterMesDonnees(): Response
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        $donnees = $this->exportService->exporter($utilisateur);

        // Self-service : la personne accède à SES propres données → pas de journal
        // d'administration, seulement une trace technique Monolog. Lecture pure.
        $this->logger->info('Export self-service des données personnelles', [
            'user_id' => $utilisateur->getId(),
        ]);

        return $this->telechargerJson($donnees, 'mes-donnees');
    }

    /**
     * Réponse JSON en téléchargement (UTF-8, pretty-print). Le nom de fichier ne
     * contient aucune donnée nominative : segment fourni par l'appelant + date.
     *
     * @param array<string, mixed> $donnees
     */
    private function telechargerJson(array $donnees, string $identifiant): JsonResponse
    {
        $json = json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        // Horodatage jusqu'à la seconde (heure Réunion) : nom de fichier unique →
        // évite les suffixes (1)(2) du navigateur sur téléchargements successifs.
        $horodatage = (new \DateTimeImmutable('now', new \DateTimeZone('Indian/Reunion')))->format('Ymd-His');

        $reponse = new JsonResponse($json, Response::HTTP_OK, [], json: true);
        $reponse->headers->set('Content-Type', 'application/json; charset=utf-8');
        $reponse->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="creaslot-%s-%s.json"', $identifiant, $horodatage),
        );

        return $reponse;
    }
}
