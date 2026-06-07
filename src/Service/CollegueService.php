<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\CollegueDTO;
use App\Entity\Utilisateur;
use App\Repository\CreneauRepository;
use App\Repository\UtilisateurRepository;

/**
 * Calcule le statut en temps réel et le prochain RDV de chaque collègue Personnel.
 * Seuls les collègues ayant au moins un créneau actif (futur ou en cours) sont visibles.
 */
class CollegueService
{
    public const STATUT_EN_RDV = 'EN_RDV';
    public const STATUT_LIBRE = 'LIBRE';

    public function __construct(
        private readonly UtilisateurRepository $utilisateurRepository,
        private readonly CreneauRepository $creneauRepository,
    ) {
    }

    /**
     * Retourne les collègues actifs ayant au moins un créneau actif futur ou en cours,
     * avec leur statut calculé en temps réel.
     *
     * @return CollegueDTO[]
     */
    public function getCollegues(
        Utilisateur $current,
        ?int $serviceId,
        bool $disponiblesOnly,
    ): array {
        $collegues = $this->utilisateurRepository->findOtherPersonnel($current, $serviceId);
        $maintenant = new \DateTimeImmutable();

        $dtos = [];
        foreach ($collegues as $collegue) {
            if (!$this->aAuMoinsUnCreneauActif($collegue, $maintenant)) {
                continue;
            }
            $dtos[] = $this->construireDTO($collegue, $maintenant);
        }

        if ($disponiblesOnly) {
            return array_values(array_filter(
                $dtos,
                fn (CollegueDTO $dto) => $dto->statut === self::STATUT_LIBRE,
            ));
        }

        return $dtos;
    }

    /**
     * Retourne le statut actuel d'un utilisateur : EN_RDV ou LIBRE.
     */
    public function getStatut(Utilisateur $utilisateur): string
    {
        $maintenant = new \DateTimeImmutable();
        $creneauEnCours = $this->creneauRepository->findCreneauEnCoursAvecRdv($utilisateur, $maintenant);

        return $creneauEnCours !== null ? self::STATUT_EN_RDV : self::STATUT_LIBRE;
    }

    /**
     * Vérifie qu'un utilisateur possède au moins un créneau actif futur ou en cours.
     * Permet de masquer les collègues sans disponibilité visible.
     */
    public function aAuMoinsUnCreneauActif(Utilisateur $utilisateur, \DateTimeImmutable $maintenant): bool
    {
        return $this->creneauRepository->existeCreneauActifFuturOuEnCours($utilisateur, $maintenant);
    }

    /**
     * Construit le DTO d'un collègue en exposant uniquement la date du prochain RDV
     * (pas l'entité Reservation) — minimisation RGPD pour ne jamais exposer
     * l'identité de l'Auditeur côté autres Personnels.
     */
    private function construireDTO(Utilisateur $collegue, \DateTimeImmutable $maintenant): CollegueDTO
    {
        $creneauEnCours = $this->creneauRepository->findCreneauEnCoursAvecRdv($collegue, $maintenant);
        $statut = $creneauEnCours !== null ? self::STATUT_EN_RDV : self::STATUT_LIBRE;
        $heureFinRdv = $creneauEnCours?->getDateFin()->format('H\hi');
        $prochainCreneau = $this->creneauRepository->findNextReservedCreneau($collegue);
        $prochainRdvDate = $prochainCreneau?->getDateDebut();

        return new CollegueDTO($collegue, $statut, $heureFinRdv, $prochainRdvDate);
    }
}
