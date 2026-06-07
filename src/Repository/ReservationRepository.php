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
     * Compte les réservations ACTIVE dont le créneau n'est pas encore terminé
     * (RDV à venir). KPI « Réservations actives » du tableau de bord Super-admin
     * (US-5.1).
     *
     * Agrégat scalaire (COUNT) : aucune entité hydratée. Le filtre `c.estActif`
     * est un garde-fou défensif — une réservation ACTIVE devrait toujours porter
     * sur un créneau actif (la suppression d'un créneau annule sa réservation).
     */
    public function countActivesAVenir(\DateTimeImmutable $maintenant): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->innerJoin('r.creneau', 'c')
            ->andWhere('r.statut = :statutActif')
            ->andWhere('c.estActif = true')
            ->andWhere('c.dateFin > :maintenant')
            ->setParameter('statutActif', StatutReservation::ACTIVE)
            ->setParameter('maintenant', $maintenant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Retourne les réservations ACTIVE dont le créneau a lieu dans la plage donnée
     * et qui n'ont pas encore reçu de rappel email.
     *
     * Utilisée par EnvoyerRappelsJ1Command (US-4.6) pour le cron quotidien J-1
     * à 18h Réunion : on cherche tous les RDV de demain sans rappel envoyé.
     *
     * Filtre garde-fou estActif = true sur le Creneau pour exclure les
     * créneaux soft-deleted (cohérent avec la sémantique métier).
     *
     * @param \DateTimeImmutable $demainDebut Demain 00:00:00 (timezone applicative Réunion)
     * @param \DateTimeImmutable $demainFin   Demain 23:59:59 (timezone applicative Réunion)
     *
     * @return Reservation[]
     */
    public function findActivesPourDemainSansRappel(
        \DateTimeImmutable $demainDebut,
        \DateTimeImmutable $demainFin,
    ): array {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.creneau', 'c')
            ->andWhere('r.statut = :statut')
            ->andWhere('r.rappelEnvoyeAt IS NULL')
            ->andWhere('c.dateDebut BETWEEN :debut AND :fin')
            ->andWhere('c.estActif = true')
            ->setParameter('statut', StatutReservation::ACTIVE)
            ->setParameter('debut', $demainDebut)
            ->setParameter('fin', $demainFin)
            ->orderBy('c.dateDebut', 'ASC')
            ->getQuery()
            ->getResult();
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
     * Toutes les réservations d'un Auditeur (tous statuts), pour l'export RGPD
     * (US-5.6). Jointures eager (créneau, type, Personnel) pour éviter le N+1.
     *
     * `leftJoin` plutôt qu'`innerJoin` : ces relations sont non-nullables, donc
     * équivalent en pratique, mais on garantit qu'aucune ligne ne disparaît de
     * l'export (complétude) même en cas d'anomalie de données.
     *
     * @return Reservation[]
     */
    public function findAllPourExport(Utilisateur $auditeur): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.creneau', 'c')->addSelect('c')
            ->leftJoin('c.typeRdv', 't')->addSelect('t')
            ->leftJoin('c.utilisateur', 'p')->addSelect('p')
            ->andWhere('r.utilisateur = :auditeur')
            ->setParameter('auditeur', $auditeur)
            ->orderBy('c.dateDebut', 'DESC')
            ->getQuery()
            ->getResult();
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
