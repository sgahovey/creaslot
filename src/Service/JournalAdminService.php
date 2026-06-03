<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\JournalAdmin;
use App\Entity\Utilisateur;
use App\Enum\TypeActionJournal;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Enregistre les actions d'administration sur les comptes dans le journal
 * d'accountability (US-5.5).
 *
 * Contrat d'intégrité : `enregistrer()` fait UNIQUEMENT `persist()` — le `flush()`
 * reste à la charge du contrôleur appelant, de sorte que l'action métier ET sa
 * trace soient commités dans la MÊME unité de travail (atomicité : aucun événement
 * perdu, aucune trace orpheline).
 *
 * Acteur et cible sont FIGÉS (id + libellé au moment de l'action) : la trace reste
 * lisible même après renommage ou suppression des comptes concernés.
 */
final readonly class JournalAdminService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public function enregistrer(
        TypeActionJournal $type,
        Utilisateur $acteur,
        ?Utilisateur $cible = null,
        ?string $details = null,
    ): void {
        $entree = (new JournalAdmin())
            ->setTypeAction($type)
            ->setActeurId((int) $acteur->getId())
            ->setActeurLibelle($acteur->getNomComplet())
            ->setCibleId($cible?->getId())
            ->setCibleLibelle($cible?->getNomComplet())
            ->setDetails($details);

        $this->entityManager->persist($entree);
    }
}
