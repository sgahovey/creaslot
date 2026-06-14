<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JournalAdmin;
use App\Enum\TypeActionJournal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JournalAdmin>
 */
class JournalAdminRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JournalAdmin::class);
    }

    /**
     * Entrées du journal, les plus récentes d'abord, paginées et filtrables par
     * type d'action (US-5.5). `id DESC` départage les entrées de même seconde
     * (l'ordre reste déterministe). Aucun JOIN : acteur/cible sont figés dans
     * l'entrée (libellés), il n'y a pas de FK.
     *
     * @return Paginator<JournalAdmin>
     */
    public function findPourAdmin(int $page, int $limit = 25, ?TypeActionJournal $typeAction = null): Paginator
    {
        $qb = $this->createQueryBuilder('j')
            ->orderBy('j.dateAction', 'DESC')
            ->addOrderBy('j.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        if ($typeAction !== null) {
            $qb->andWhere('j.typeAction = :type')->setParameter('type', $typeAction);
        }

        return new Paginator($qb->getQuery(), false);
    }

    /**
     * Supprime les entrées antérieures au seuil de conservation (RGPD, limitation
     * de la conservation — art. 5.1.e). La suppression est bornée par la SEULE date
     * (`dateAction < :seuil`) : jamais par id ni par acteur, afin de préserver le
     * caractère append-only du journal — on n'efface que ce qui a dépassé la durée
     * de conservation documentée, pas une entrée choisie.
     *
     * @return int nombre d'entrées supprimées
     */
    public function purgerAvant(\DateTimeImmutable $seuil): int
    {
        return (int) $this->createQueryBuilder('j')
            ->delete()
            ->where('j.dateAction < :seuil')
            ->setParameter('seuil', $seuil)
            ->getQuery()
            ->execute();
    }
}
