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
        private readonly DateFormatterService $dateFormatter,
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

        // Trois agrégats par lot (DT-10) : statut, prochain RDV et visibilité de TOUS
        // les collègues en un nombre de requêtes constant, au lieu de ~3 requêtes par
        // collègue. L'ordre de findOtherPersonnel (service puis nom) est préservé : on
        // ne fait que consulter ces tables par identifiant.
        $ids = array_values(array_map(static fn (Utilisateur $collegue): int => (int) $collegue->getId(), $collegues));
        $idsVisibles = array_flip($this->creneauRepository->findIdsAvecCreneauActifFuturOuEnCours($ids, $maintenant));
        $finsRdvEnCours = $this->creneauRepository->findFinsRdvEnCoursParUtilisateur($ids, $maintenant);
        $prochainsRdv = $this->creneauRepository->findProchainsRdvParUtilisateur($ids, $maintenant);

        $dtos = [];
        foreach ($collegues as $collegue) {
            $id = (int) $collegue->getId();
            if (!isset($idsVisibles[$id])) {
                continue;
            }

            $finRdvEnCours = $finsRdvEnCours[$id] ?? null;
            $statut = $finRdvEnCours !== null ? self::STATUT_EN_RDV : self::STATUT_LIBRE;

            $dtos[] = new CollegueDTO(
                $collegue,
                $statut,
                $finRdvEnCours !== null ? $this->dateFormatter->pourHeureCompacte($finRdvEnCours) : null,
                $prochainsRdv[$id] ?? null,
            );
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
}
