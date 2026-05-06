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
    public function findActivesParAuditeur(Utilisateur $auditeur): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.creneau', 'c')
            ->andWhere('r.auditeur = :auditeur')
            ->andWhere('r.statut = :statut')
            ->setParameter('auditeur', $auditeur)
            ->setParameter('statut', StatutReservation::ACTIVE)
            ->orderBy('c.debutAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
