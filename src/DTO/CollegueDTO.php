<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Utilisateur;

/**
 * Transfert de données pour la vue d'un collègue Personnel.
 * Aucune logique — uniquement un conteneur de données affichables.
 *
 * Minimisation RGPD : ne contient PAS l'entité Reservation du prochain RDV
 * pour empêcher toute fuite ultérieure de l'identité de l'Auditeur via
 * dto.prochainRdv.utilisateur dans les templates. Seule la date est exposée.
 */
final class CollegueDTO
{
    public function __construct(
        public readonly Utilisateur $utilisateur,
        public readonly string $statut,
        public readonly ?string $heureFinRdv,
        public readonly ?\DateTimeImmutable $prochainRdvDate,
    ) {
    }
}
