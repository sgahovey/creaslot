<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Creneau;
use App\Entity\Utilisateur;
use App\Enum\StatutReservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Creneau>
 */
class CreneauRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Creneau::class);
    }

    /**
     * Retourne les créneaux du Personnel avec filtres, paginés.
     * JOINs eagerly chargés pour éviter le problème N+1.
     *
     * @return Paginator<Creneau>
     */
    public function findByPersonnelWithFilters(
        Utilisateur $personnel,
        string $filtre,
        int $page,
        int $limit = 10,
    ): Paginator {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.typeRdv', 't')->addSelect('t')
            ->leftJoin('c.reservation', 'r')->addSelect('r')
            ->leftJoin('r.utilisateur', 'a')->addSelect('a')
            ->where('c.utilisateur = :personnel')
            ->setParameter('personnel', $personnel)
            ->orderBy('c.dateDebut', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        $this->appliquerFiltre($qb, $filtre);

        // fetchJoinCollection: false — pas de OneToMany dans les JOINs (pas de fan-out)
        return new Paginator($qb->getQuery(), false);
    }

    /**
     * Applique les conditions de filtre sur le QueryBuilder.
     * 4 filtres : tous (défaut), a_venir, passes, annules.
     */
    private function appliquerFiltre(QueryBuilder $qb, string $filtre): void
    {
        $now = new \DateTimeImmutable();

        if ($filtre === 'a_venir') {
            $qb->andWhere('c.estActif = true')->andWhere('c.dateDebut > :now')->setParameter('now', $now);
        } elseif ($filtre === 'passes') {
            $qb->andWhere('c.dateFin < :now')->setParameter('now', $now);
        } elseif ($filtre === 'annules') {
            $qb->andWhere('c.estActif = false');
        } else {
            // 'tous' : actifs (comportement par défaut)
            $qb->andWhere('c.estActif = true');
        }
    }

    /**
     * Créneaux actifs du personnel dont l'intervalle chevauche ]debutPlage, finPlage[.
     * JOIN eager pour éviter le N+1 (type RDV, réservation, auditeur).
     *
     * @return Creneau[]
     */
    public function findByPersonnelInDateRange(
        Utilisateur $personnel,
        \DateTimeImmutable $debutPlage,
        \DateTimeImmutable $finPlage,
        bool $reserveOnly = false,
    ): array {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.typeRdv', 't')->addSelect('t');

        if ($reserveOnly) {
            $qb->innerJoin('c.reservation', 'r')->addSelect('r')
                ->andWhere('r.statut = :statutReservationActive')
                ->setParameter('statutReservationActive', StatutReservation::ACTIVE);
        } else {
            $qb->leftJoin('c.reservation', 'r')->addSelect('r');
        }

        return $qb
            ->leftJoin('r.utilisateur', 'aud')->addSelect('aud')
            ->andWhere('c.utilisateur = :personnel')
            ->andWhere('c.estActif = true')
            ->andWhere('c.dateFin > :debut')
            ->andWhere('c.dateDebut < :fin')
            ->setParameter('personnel', $personnel)
            ->setParameter('debut', $debutPlage)
            ->setParameter('fin', $finPlage)
            ->orderBy('c.dateDebut', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne les créneaux disponibles (sans réservation) d'un Personnel, triés par date de début.
     *
     * @return Creneau[]
     */
    public function findDisponiblesParUtilisateur(Utilisateur $utilisateur): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.reservation', 'r')
            ->andWhere('c.utilisateur = :utilisateur')
            ->andWhere('c.estActif = true')
            ->andWhere('r.id IS NULL')
            ->setParameter('utilisateur', $utilisateur)
            ->orderBy('c.dateDebut', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Prochain créneau actif réservé (réservation ACTIVE) pour l'agenda.
     */
    /**
     * Créneaux actifs du personnel dont l’intervalle chevauche ]debut, fin[
     * (sans adjacence : fin == début d’un autre créneau n’est pas un chevauchement).
     *
     * @return list<Creneau>
     */
    public function findChevauchements(
        Utilisateur $utilisateur,
        \DateTimeImmutable $debut,
        \DateTimeImmutable $fin,
        ?int $excludeId = null,
    ): array {
        $qb = $this->createQueryBuilder('c')
            ->innerJoin('c.typeRdv', 't')->addSelect('t')
            ->andWhere('c.utilisateur = :utilisateur')
            ->setParameter('utilisateur', $utilisateur)
            ->andWhere('c.estActif = true')
            ->andWhere('c.dateFin > :maintenantChevauch')
            ->setParameter('maintenantChevauch', new \DateTimeImmutable())
            ->andWhere('c.dateDebut < :nouveauFin')
            ->andWhere('c.dateFin > :nouveauDebut')
            ->setParameter('nouveauDebut', $debut)
            ->setParameter('nouveauFin', $fin)
            ->orderBy('c.dateDebut', 'ASC');

        if ($excludeId !== null) {
            $qb->andWhere('c.id != :excludeId')->setParameter('excludeId', $excludeId);
        }

        /** @var list<Creneau> */
        return $qb->getQuery()->getResult();
    }

    public function findNextReservedCreneau(Utilisateur $personnel): ?Creneau
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.reservation', 'r')->addSelect('r')
            ->leftJoin('r.utilisateur', 'a')->addSelect('a')
            ->andWhere('r.statut = :statutActif')
            ->setParameter('statutActif', StatutReservation::ACTIVE)
            ->andWhere('c.utilisateur = :personnel')
            ->setParameter('personnel', $personnel)
            ->andWhere('c.estActif = true')
            ->andWhere('c.dateDebut > :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('c.dateDebut', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Retourne le créneau actif en cours (dateDebut <= $maintenant < dateFin)
     * ayant une réservation ACTIVE, pour calculer le statut "En RDV".
     */
    public function findCreneauEnCoursAvecRdv(
        Utilisateur $utilisateur,
        \DateTimeImmutable $maintenant,
    ): ?Creneau {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.reservation', 'r')->addSelect('r')
            ->leftJoin('r.utilisateur', 'a')->addSelect('a')
            ->andWhere('c.utilisateur = :utilisateur')
            ->andWhere('c.estActif = true')
            ->andWhere('c.dateDebut <= :maintenant')
            ->andWhere('c.dateFin > :maintenant')
            ->andWhere('r.statut = :statutActif')
            ->setParameter('utilisateur', $utilisateur)
            ->setParameter('maintenant', $maintenant)
            ->setParameter('statutActif', StatutReservation::ACTIVE)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Vérifie l'existence d'au moins un créneau actif futur ou en cours (dateFin >= $maintenant).
     * Utilisé pour masquer de la liste les collègues sans disponibilité visible.
     */
    public function existeCreneauActifFuturOuEnCours(
        Utilisateur $utilisateur,
        \DateTimeImmutable $maintenant,
    ): bool {
        $count = (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.utilisateur = :utilisateur')
            ->andWhere('c.estActif = true')
            ->andWhere('c.dateFin >= :maintenant')
            ->setParameter('utilisateur', $utilisateur)
            ->setParameter('maintenant', $maintenant)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
