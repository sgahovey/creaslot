<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Reservation;
use App\Entity\Utilisateur;

/**
 * Transfert de données pour la vue d'un collègue Personnel.
 * Aucune logique — uniquement un conteneur de données affichables.
 */
final class CollegueDTO
{
    public function __construct(
        public readonly Utilisateur $utilisateur,
        public readonly string      $statut,
        public readonly ?string     $heureFinRdv,
        public readonly ?Reservation $prochainRdv,
    ) {}
}
