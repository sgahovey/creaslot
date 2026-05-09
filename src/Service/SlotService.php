<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Creneau;
use App\Entity\Utilisateur;
use App\Repository\CreneauRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Psr\Log\LoggerInterface;

/**
 * Règle métier : un personnel ne peut pas avoir deux créneaux actifs qui se chevauchent dans le temps.
 */
final readonly class SlotService
{
    public function __construct(
        private CreneauRepository $creneauRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<Creneau> créneaux actifs qui intersectent strictement ]debut, fin[ (voir repository)
     */
    public function detecteChevauchements(
        Utilisateur $user,
        DateTimeInterface $debut,
        DateTimeInterface $fin,
        ?int $excludeId = null,
    ): array {
        return $this->creneauRepository->findChevauchements(
            $user,
            DateTimeImmutable::createFromInterface($debut),
            DateTimeImmutable::createFromInterface($fin),
            $excludeId,
        );
    }

    /**
     * @param Creneau  $creneau   créneau candidat (dates et utilisateur déjà renseignés)
     * @param ?int     $excludeId identifiant à exclure (ex.: en modification) ; sinon l'id du créneau s'il existe
     */
    public function chevaucheAvecExistant(Creneau $creneau, ?int $excludeId = null): bool
    {
        $exclure = $excludeId ?? $creneau->getId();

        return $this->detecteChevauchements(
            $creneau->getUtilisateur(),
            $creneau->getDateDebut(),
            $creneau->getDateFin(),
            $exclure,
        ) !== [];
    }

    /**
     * Message flash métier pour le premier créneau en conflit.
     */
    public function construireMessageChevauchement(Creneau $conflit): string
    {
        return sprintf(
            'Ce créneau chevauche un créneau existant : %s du %s de %s à %s.',
            $conflit->getTypeRdv()->getLibelle(),
            $conflit->getDateDebut()->format('d/m/Y'),
            $conflit->getDateDebut()->format('H:i'),
            $conflit->getDateFin()->format('H:i'),
        );
    }

    public function enregistrerChevauchementDetecte(
        Creneau $candidat,
        Creneau $conflit,
        string $contexte,
    ): void {
        $this->logger->warning(
            'Chevauchement de créneau détecté',
            [
                'contexte'               => $contexte,
                'candidat_user_id'       => $candidat->getUtilisateur()->getId(),
                'candidat_date_debut'    => $candidat->getDateDebut()->format(\DateTimeInterface::ATOM),
                'candidat_date_fin'      => $candidat->getDateFin()->format(\DateTimeInterface::ATOM),
                'creneau_conflit_id'     => $conflit->getId(),
                'conflit_date_debut'     => $conflit->getDateDebut()->format(\DateTimeInterface::ATOM),
                'conflit_date_fin'       => $conflit->getDateFin()->format(\DateTimeInterface::ATOM),
            ],
        );
    }

    /** @param list<Creneau> $conflits */
    public function enregistrerPremierChevauchement(
        Creneau $candidat,
        array $conflits,
        string $contexte,
    ): void {
        $premier = $conflits[0] ?? null;
        if ($premier instanceof Creneau) {
            $this->enregistrerChevauchementDetecte($candidat, $premier, $contexte);
        }
    }
}
