<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Creneau;

/**
 * Transforme les entités Creneau en événements JSON FullCalendar v6.
 */
final class CreneauCalendarSerializer
{
    /**
     * @param array<int|string, Creneau> $creneaux
     *
     * @return list<array<string, mixed>>
     */
    public function toCalendarEvents(array $creneaux): array
    {
        $evenements = [];
        foreach ($creneaux as $creneau) {
            if (!$creneau->getId()) {
                continue;
            }
            $evenements[] = $this->serializerEvenement($creneau);
        }

        return $evenements;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializerEvenement(Creneau $creneau): array
    {
        $typeRdv = $creneau->getTypeRdv();
        $auditeur = $creneau->getAuditeurReservation();

        $nomAuditeur = '';
        if ($auditeur !== null) {
            $nomAuditeur = trim($auditeur->getPrenom() . ' ' . $auditeur->getNom());
        }

        return [
            'id'            => $creneau->getId(),
            'title'         => $typeRdv->getLibelle(),
            'start'         => $creneau->getDateDebut()->format(\DateTimeInterface::ATOM),
            'end'           => $creneau->getDateFin()->format(\DateTimeInterface::ATOM),
            'color'         => $typeRdv->getCouleurHex(),
            'extendedProps' => [
                'typeRdv'        => $typeRdv->getLibelle(),
                'typeCouleurHex' => $typeRdv->getCouleurHex(),
                'commentaire'    => $creneau->getCommentaireAuditeur() ?? '',
                'motifAuditeur'  => $creneau->getReservationActive()?->getCommentaireAuditeur() ?? '',
                'reserve'        => $creneau->isReserve(),
                'auditeurNom'    => $nomAuditeur,
                'estActif'       => $creneau->isEstActif(),
                'isPasse'        => $creneau->isPasse(),
            ],
        ];
    }
}
