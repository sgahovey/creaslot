<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Reservation;
use App\Entity\Utilisateur;
use App\Enum\StatutReservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
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

    /**
     * Retourne les réservations d'un Auditeur, paginées et filtrées.
     * JOINs eager pour éviter le N+1 (créneau, type RDV, personnel, service).
     *
     * Filtres acceptés : 'toutes' (défaut), 'a_venir', 'passees', 'annulees'.
     * Tri : ASC sur 'a_venir' (prochain RDV en premier), DESC sinon.
     *
     * @return Paginator<Reservation>
     */
    public function findByAuditeurWithFilters(
        Utilisateur $auditeur,
        string $filtre,
        int $page,
        int $limit = 12,
    ): Paginator {
        $qb = $this->createQueryBuilder('r')
            ->innerJoin('r.creneau', 'c')->addSelect('c')
            ->innerJoin('c.typeRdv', 't')->addSelect('t')
            ->innerJoin('c.utilisateur', 'p')->addSelect('p')
            ->leftJoin('p.service', 's')->addSelect('s')
            ->andWhere('r.utilisateur = :auditeur')
            ->setParameter('auditeur', $auditeur)
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        $this->appliquerFiltre($qb, $filtre);
        $this->appliquerTri($qb, $filtre);

        // fetchJoinCollection: false — uniquement des ToOne dans les JOINs (pas de fan-out)
        return new Paginator($qb, false);
    }

    /**
     * Applique les conditions de filtre sur le QueryBuilder.
     * 4 filtres : toutes (défaut, aucune condition), a_venir, passees, annulees.
     */
    private function appliquerFiltre(QueryBuilder $qb, string $filtre): void
    {
        $now = new \DateTimeImmutable();

        if ($filtre === 'a_venir') {
            $qb->andWhere('c.dateDebut > :now')
                ->andWhere('r.statut = :statutActif')
                ->setParameter('now', $now)
                ->setParameter('statutActif', StatutReservation::ACTIVE);
        } elseif ($filtre === 'passees') {
            $qb->andWhere('c.dateFin < :now')
                ->setParameter('now', $now);
        } elseif ($filtre === 'annulees') {
            $qb->andWhere('r.statut = :statutAnnulee')
                ->setParameter('statutAnnulee', StatutReservation::ANNULEE);
        }
        // 'toutes' : aucun filtre
    }

    /**
     * ASC sur 'a_venir' pour afficher le prochain RDV en premier, DESC sinon.
     */
    private function appliquerTri(QueryBuilder $qb, string $filtre): void
    {
        $direction = $filtre === 'a_venir' ? 'ASC' : 'DESC';
        $qb->orderBy('c.dateDebut', $direction);
    }
}
