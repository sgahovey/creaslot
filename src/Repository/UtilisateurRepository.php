<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
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

    /**
     * Liste paginée de TOUS les comptes (tous rôles, actifs et inactifs), pour
     * l'écran de gestion des comptes Super-admin (US-5.3), triée par nom puis prénom.
     *
     * `leftJoin('u.service')->addSelect('s')` charge le service en une requête
     * (anti-N+1, cf. [[DT-10]]). `fetchJoinCollection: false` : le service est un
     * ManyToOne (pas de fan-out), inutile de dédupliquer les lignes.
     *
     * @return Paginator<Utilisateur>
     */
    public function findAllPourAdmin(int $page, int $limit = 20): Paginator
    {
        $qb = $this->createQueryBuilder('u')
            ->leftJoin('u.service', 's')->addSelect('s')
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return new Paginator($qb->getQuery(), false);
    }

    /**
     * Nombre de comptes ayant le rôle SUPER_ADMIN. Sert à empêcher de retirer le
     * dernier super-administrateur (garde anti lock-out, vérifiée côté contrôleur).
     *
     * Compte par rôle, sans filtre `estActif` : l'activation des comptes (US-5.4)
     * affinera ce comptage si besoin (super-admins actifs uniquement).
     */
    public function countSuperAdmins(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.role = :role')
            ->setParameter('role', RoleUtilisateur::SUPER_ADMIN)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Nombre de comptes SUPER_ADMIN encore actifs. Sert aux gardes anti lock-out :
     * un super-admin inactif ne peut plus se connecter, il ne compte donc pas comme
     * repli. Utilisé pour empêcher de retirer/désactiver le dernier super-admin
     * réellement utilisable (US-5.4, et raffinement de la garde de rôle US-5.3).
     */
    public function countSuperAdminsActifs(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.role = :role')
            ->andWhere('u.estActif = true')
            ->setParameter('role', RoleUtilisateur::SUPER_ADMIN)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
