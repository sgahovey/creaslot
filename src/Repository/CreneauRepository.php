<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Creneau;
use App\Entity\Utilisateur;
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
}
