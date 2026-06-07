<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Creneau;

/**
 * Transforme les créneaux en événements JSON FullCalendar v6 pour la vue globale
 * occupé/libre du Super-admin (US-5.7).
 *
 * Volontairement distinct de CreneauCalendarSerializer (vue Personnel) :
 * - l'occupation provient d'un ensemble d'identifiants pré-calculé
 *   (CreneauRepository::findIdsCreneauxOccupesDansPlage), jamais d'un lazy-load
 *   de la collection `reservations` (anti N+1) ;
 * - aucune donnée d'auditeur n'est exposée (RGPD, minimisation) : ni nom, ni
 *   commentaire, ni appel à getAuditeurReservation(). Le titre identifie le
 *   Personnel propriétaire du créneau, pas l'auditeur réservant.
 */
final class OccupationCalendarSerializer
{
    /**
     * @param array<int|string, Creneau> $creneaux
     * @param list<int>                  $idsOccupes identifiants des créneaux occupés
     *
     * @return list<array<string, mixed>>
     */
    public function toCalendarEvents(array $creneaux, array $idsOccupes): array
    {
        $evenements = [];
        foreach ($creneaux as $creneau) {
            if (!$creneau->getId()) {
                continue;
            }
            $evenements[] = $this->serializerEvenement($creneau, $idsOccupes);
        }

        return $evenements;
    }

    /**
     * @param list<int> $idsOccupes
     *
     * @return array<string, mixed>
     */
    private function serializerEvenement(Creneau $creneau, array $idsOccupes): array
    {
        $typeRdv = $creneau->getTypeRdv();
        $personnelNom = $creneau->getUtilisateur()->getNomComplet();
        $occupe = in_array($creneau->getId(), $idsOccupes, true);

        return [
            'id'            => $creneau->getId(),
            'title'         => $personnelNom . ' · ' . $typeRdv->getLibelle(),
            'start'         => $creneau->getDateDebut()->format(\DateTimeInterface::ATOM),
            'end'           => $creneau->getDateFin()->format(\DateTimeInterface::ATOM),
            'color'         => $typeRdv->getCouleurHex(),
            'extendedProps' => [
                'personnelNom'   => $personnelNom,
                'typeRdv'        => $typeRdv->getLibelle(),
                'typeCouleurHex' => $typeRdv->getCouleurHex(),
                'occupe'         => $occupe,
                'etat'           => $occupe ? 'Occupé' : 'Libre',
                'isPasse'        => $creneau->isPasse(),
            ],
        ];
    }
}
