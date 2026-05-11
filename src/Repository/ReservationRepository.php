<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Reservation;
use App\Entity\Utilisateur;
use App\Enum\StatutReservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reservation>
 */
class ReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservation::class);
    }

    /**
     * Retourne les réservations actives d'un Auditeur, triées par date de début du créneau.
     *
     * @return Reservation[]
     */
    public function findActivesParUtilisateur(Utilisateur $utilisateur): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.creneau', 'c')
            ->andWhere('r.utilisateur = :utilisateur')
            ->andWhere('r.statut = :statut')
            ->setParameter('utilisateur', $utilisateur)
            ->setParameter('statut', StatutReservation::ACTIVE)
            ->orderBy('c.dateDebut', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Vérifie si l'Auditeur a déjà une réservation ACTIVE qui chevauche la plage horaire donnée.
     * Utilisé pour empêcher les réservations simultanées.
     */
    public function existeReservationActiveEnChevauchement(
        Utilisateur $auditeur,
        \DateTimeImmutable $debut,
        \DateTimeImmutable $fin,
    ): bool {
        $count = (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->join('r.creneau', 'c')
            ->andWhere('r.utilisateur = :auditeur')
            ->andWhere('r.statut = :statut')
            ->andWhere('c.dateDebut < :fin')
            ->andWhere('c.dateFin > :debut')
            ->setParameter('auditeur', $auditeur)
            ->setParameter('statut', StatutReservation::ACTIVE)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
