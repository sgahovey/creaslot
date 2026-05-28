<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    private const LIMIT_PAR_PAGE = 10;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * Notifications d'un destinataire, paginées (10/page), de la plus récente
     * à la plus ancienne.
     *
     * @return Paginator<Notification>
     */
    public function findByDestinatairePaginated(Utilisateur $destinataire, int $page = 1): Paginator
    {
        $qb = $this->createQueryBuilder('n')
            ->andWhere('n.destinataire = :destinataire')
            ->setParameter('destinataire', $destinataire)
            ->orderBy('n.dateCreation', 'DESC')
            ->setFirstResult(($page - 1) * self::LIMIT_PAR_PAGE)
            ->setMaxResults(self::LIMIT_PAR_PAGE);

        // fetchJoinCollection: false — aucune OneToMany fetch-jointe ici.
        return new Paginator($qb->getQuery(), false);
    }

    /**
     * Nombre de notifications NON LUES d'un destinataire (badge du menu).
     * L'index composite (id_destinataire, lu) garantit un COUNT performant.
     */
    public function countNonLues(Utilisateur $destinataire): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.destinataire = :destinataire')
            ->andWhere('n.lu = false')
            ->setParameter('destinataire', $destinataire)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Marque comme lues toutes les notifications non lues d'un destinataire.
     * Appelé à l'ouverture de la page "Mes notifications" (US-4.7, auto-lu D1).
     *
     * UPDATE DQL en masse : ne sollicite pas l'UnitOfWork (les entités déjà
     * chargées en mémoire ne sont pas synchronisées). Le caller doit donc lire
     * la liste APRÈS cet appel pour refléter l'état lu=true.
     *
     * @return int Nombre de notifications mises à jour (0 si déjà toutes lues).
     */
    public function marquerToutesLues(Utilisateur $destinataire): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->update(Notification::class, 'n')
            ->set('n.lu', ':vrai')
            ->where('n.destinataire = :destinataire')
            ->andWhere('n.lu = false')
            ->setParameter('vrai', true)
            ->setParameter('destinataire', $destinataire)
            ->getQuery()
            ->execute();
    }
}
