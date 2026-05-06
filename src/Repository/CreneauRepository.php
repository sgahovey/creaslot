<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Creneau;
use App\Entity\Utilisateur;
use App\Enum\StatutCreneau;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
     * Retourne les créneaux disponibles d'un Personnel, triés par date de début.
     *
     * @return Creneau[]
     */
    public function findDisponiblesParPersonnel(Utilisateur $personnel): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.personnel = :personnel')
            ->andWhere('c.statut = :statut')
            ->andWhere('c.estActif = true')
            ->setParameter('personnel', $personnel)
            ->setParameter('statut', StatutCreneau::DISPONIBLE)
            ->orderBy('c.debutAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
