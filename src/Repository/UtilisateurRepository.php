<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Utilisateur>
 */
class UtilisateurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Utilisateur::class);
    }

    /**
     * Recherche un utilisateur actif par email.
     */
    public function findActifByEmail(string $email): ?Utilisateur
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.email = :email')
            ->andWhere('u.estActif = true')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Retourne les autres membres du Personnel actifs (ROLE_PERSONNEL ou ROLE_SUPER_ADMIN),
     * hors l'utilisateur courant, triés par service puis par nom alphabétique.
     * Si $serviceId est fourni, filtre sur ce service uniquement.
     *
     * @return Utilisateur[]
     */
    public function findOtherPersonnel(Utilisateur $current, ?int $serviceId = null): array
    {
        $qb = $this->createQueryBuilder('u')
            ->leftJoin('u.service', 's')->addSelect('s')
            ->andWhere('u.id != :currentId')
            ->andWhere('u.estActif = true')
            ->andWhere('u.role IN (:roles)')
            ->setParameter('currentId', $current->getId())
            ->setParameter('roles', [RoleUtilisateur::PERSONNEL, RoleUtilisateur::SUPER_ADMIN])
            ->orderBy('s.nom', 'ASC')
            ->addOrderBy('u.nom', 'ASC');

        if ($serviceId !== null) {
            $qb->andWhere('s.id = :serviceId')->setParameter('serviceId', $serviceId);
        }

        /** @var Utilisateur[] */
        return $qb->getQuery()->getResult();
    }
}
