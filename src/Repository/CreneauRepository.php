<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Creneau;
use App\Entity\Reservation;
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
            ->leftJoin('c.reservations', 'r')->addSelect('r')
            ->leftJoin('r.utilisateur', 'a')->addSelect('a')
            ->where('c.utilisateur = :personnel')
            ->setParameter('personnel', $personnel)
            ->orderBy('c.dateDebut', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        $this->appliquerFiltre($qb, $filtre);

        // DT-1 : fetchJoinCollection: true — addSelect('r') hydrate désormais une
        // Collection<Reservation> (OneToMany) côté Creneau. Paginator a besoin de
        // distinguer les lignes SQL des entités root pour COUNT/LIMIT corrects.
        return new Paginator($qb->getQuery(), true);
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
            $qb->innerJoin('c.reservations', 'r')->addSelect('r')
                ->andWhere('r.statut = :statutReservationActive')
                ->setParameter('statutReservationActive', StatutReservation::ACTIVE);
        } else {
            $qb->leftJoin('c.reservations', 'r')->addSelect('r');
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
            ->innerJoin('c.reservations', 'r')->addSelect('r')
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
            ->innerJoin('c.reservations', 'r')->addSelect('r')
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
     * Retourne les créneaux disponibles pour les Auditeurs, paginés.
     * Un créneau est disponible si : actif, futur, personnel actif, sans réservation ACTIVE.
     * JOINs eager pour éviter le problème N+1 (typeRdv, utilisateur, service).
     *
     * @return Paginator<Creneau>
     */
    public function findDisponibles(
        ?int $typeRdvId,
        ?int $serviceId,
        ?\DateTimeImmutable $date,
        int $page,
        int $limit = 12,
    ): Paginator {
        $qb = $this->createQueryBuilder('c')
            ->innerJoin('c.typeRdv', 't')->addSelect('t')
            ->innerJoin('c.utilisateur', 'u')->addSelect('u')
            ->leftJoin('u.service', 's')->addSelect('s')
            ->andWhere('c.estActif = true')
            ->andWhere('c.dateDebut > :now')
            ->andWhere('u.estActif = true')
            // DT-1 : un créneau est disponible ssi AUCUNE Reservation ACTIVE ne le réserve.
            // Les Reservations ANNULEE ne le rendent plus indisponible (refacto OneToMany).
            // NOT EXISTS plutôt que LEFT JOIN + (r.id IS NULL OR r.statut != ACTIVE) :
            // en OneToMany, un créneau avec [ACTIVE + ANNULEE] produirait 2 lignes, la
            // ligne ANNULEE matcherait le filtre et le créneau apparaîtrait à tort disponible.
            ->andWhere(
                'NOT EXISTS (
                    SELECT 1
                    FROM App\Entity\Reservation r_active
                    WHERE r_active.creneau = c
                        AND r_active.statut = :statutActif
                )'
            )
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('statutActif', StatutReservation::ACTIVE)
            ->orderBy('c.dateDebut', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        $this->appliquerFiltresDisponibles($qb, $typeRdvId, $serviceId, $date);

        return new Paginator($qb->getQuery(), false);
    }

    private function appliquerFiltresDisponibles(
        QueryBuilder $qb,
        ?int $typeRdvId,
        ?int $serviceId,
        ?\DateTimeImmutable $date,
    ): void {
        if ($typeRdvId !== null) {
            $qb->andWhere('t.id = :typeRdvId')->setParameter('typeRdvId', $typeRdvId);
        }

        if ($serviceId !== null) {
            $qb->andWhere('s.id = :serviceId')->setParameter('serviceId', $serviceId);
        }

        if ($date !== null) {
            $qb->andWhere('c.dateDebut >= :debutJour')
               ->andWhere('c.dateDebut < :finJour')
               ->setParameter('debutJour', $date->setTime(0, 0, 0))
               ->setParameter('finJour', $date->setTime(23, 59, 59));
        }
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

    /**
     * Compte les créneaux actifs dont le début tombe dans la fenêtre [debut, fin].
     * Dénominateur du taux d'occupation du tableau de bord Super-admin (US-5.1) :
     * l'offre totale de créneaux sur la période.
     *
     * Agrégat scalaire (COUNT) : aucune entité hydratée.
     */
    public function countActifsDansFenetre(\DateTimeImmutable $debut, \DateTimeImmutable $fin): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.estActif = true')
            ->andWhere('c.dateDebut BETWEEN :debut AND :fin')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Compte les créneaux actifs de la fenêtre [debut, fin] ayant au moins une
     * réservation ACTIVE. Numérateur du taux d'occupation (US-5.1).
     *
     * `EXISTS` (et non un JOIN) pour éviter le fan-out OneToMany [[DT-1]] : un
     * créneau ayant plusieurs réservations (ex. une ACTIVE + une ANNULEE après
     * re-réservation) n'est compté qu'une seule fois. Même fenêtre `:debut`/`:fin`
     * que `countActifsDansFenetre` pour garantir la cohérence numérateur/dénominateur.
     */
    public function countReservesActifsDansFenetre(\DateTimeImmutable $debut, \DateTimeImmutable $fin): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.estActif = true')
            ->andWhere('c.dateDebut BETWEEN :debut AND :fin')
            ->andWhere(
                'EXISTS (
                    SELECT 1
                    FROM App\Entity\Reservation r_active
                    WHERE r_active.creneau = c
                        AND r_active.statut = :statutActif
                )'
            )
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->setParameter('statutActif', StatutReservation::ACTIVE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Statistiques d'occupation agrégées par jour calendaire sur [debut, fin],
     * pour le graphique du tableau de bord Super-admin (US-5.2).
     *
     * @return array<string, array{offre: int, reserves: int}> indexé par jour
     *         'YYYY-MM-DD' (ex. ['2026-05-30' => ['offre' => 4, 'reserves' => 2]]).
     *         Seuls les jours ayant au moins un créneau actif sont présents ; les
     *         jours vides sont comblés à 0 par le DashboardService (pas ici).
     *
     * Deux requêtes plutôt qu'une : le comptage conditionnel en une passe
     * (`COUNT(DISTINCT CASE WHEN ... THEN c.id ELSE NULL END)`) est rejeté par la
     * grammaire DQL (le `ELSE` est obligatoire et `ELSE NULL` lève une Syntax
     * Error). On agrège donc l'offre et les réservés séparément, puis on fusionne
     * par clé-jour (≤ N lignes pré-agrégées, aucune itération du dataset).
     *
     * Regroupement par `SUBSTRING(c.dateDebut, 1, 10)` : `date_debut` est stocké en
     * heure-mur Réunion (timezone applicative Indian/Reunion, UTC+4 sans DST, aucune
     * conversion Doctrine), donc l'extraction des 10 premiers caractères donne le
     * jour calendaire Réunion exact, sans décalage de minuit.
     */
    public function statistiquesOccupationParJour(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $offreParJour = $this->createQueryBuilder('c')
            ->select('SUBSTRING(c.dateDebut, 1, 10) AS jour', 'COUNT(c.id) AS offre')
            ->andWhere('c.estActif = true')
            ->andWhere('c.dateDebut BETWEEN :debut AND :fin')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->groupBy('jour')
            ->getQuery()
            ->getResult();

        $reservesParJour = $this->createQueryBuilder('c')
            ->select('SUBSTRING(c.dateDebut, 1, 10) AS jour', 'COUNT(DISTINCT c.id) AS reserves')
            ->innerJoin('c.reservations', 'r')
            ->andWhere('c.estActif = true')
            ->andWhere('c.dateDebut BETWEEN :debut AND :fin')
            ->andWhere('r.statut = :statutActif')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->setParameter('statutActif', StatutReservation::ACTIVE)
            ->groupBy('jour')
            ->getQuery()
            ->getResult();

        return $this->fusionnerOccupationParJour($offreParJour, $reservesParJour);
    }

    /**
     * Fusionne les agrégats offre/réservés par clé-jour. Un créneau réservé étant
     * toujours actif, chaque jour de $reservesParJour figure aussi dans
     * $offreParJour : on indexe donc la série sur l'offre et on y greffe les
     * réservés (0 si le jour n'a aucune réservation ACTIVE).
     *
     * @param list<array{jour: string, offre: int|string}>    $offreParJour
     * @param list<array{jour: string, reserves: int|string}> $reservesParJour
     * @return array<string, array{offre: int, reserves: int}>
     */
    private function fusionnerOccupationParJour(array $offreParJour, array $reservesParJour): array
    {
        $reservesIndexees = [];
        foreach ($reservesParJour as $ligne) {
            $reservesIndexees[$ligne['jour']] = (int) $ligne['reserves'];
        }

        $statistiques = [];
        foreach ($offreParJour as $ligne) {
            $jour = $ligne['jour'];
            $statistiques[$jour] = [
                'offre'    => (int) $ligne['offre'],
                'reserves' => $reservesIndexees[$jour] ?? 0,
            ];
        }

        return $statistiques;
    }

    /**
     * Tous les créneaux proposés par un Personnel (tous statuts), pour l'export
     * RGPD (US-5.6). Jointure `typeRdv` uniquement, de la plus récente à la plus
     * ancienne. **Aucune jointure sur `reservations`** : l'export d'un Personnel ne
     * doit jamais exposer l'identité des auditeurs ayant réservé ses créneaux.
     *
     * @return Creneau[]
     */
    public function findAllParProprietairePourExport(Utilisateur $personnel): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.typeRdv', 't')->addSelect('t')
            ->andWhere('c.utilisateur = :personnel')
            ->setParameter('personnel', $personnel)
            ->orderBy('c.dateDebut', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Tous les créneaux actifs de l'organisation dont le début tombe dans
     * [debut, fin], pour la vue globale occupé/libre du Super-admin (US-5.7).
     *
     * JOINs eager (typeRdv, personnel, service) pour éviter le N+1 lors du rendu.
     * **Aucune jointure sur `c.reservations`** : l'occupation est déterminée à part
     * par findIdsCreneauxOccupesDansPlage(), ce qui évite à la fois le fan-out
     * OneToMany [[DT-1]] et l'hydratation de l'identité des auditeurs (RGPD).
     *
     * @return Creneau[]
     */
    public function findDansPlageGlobale(
        \DateTimeImmutable $debut,
        \DateTimeImmutable $fin,
        ?int $serviceId,
        ?int $typeId,
    ): array {
        $qb = $this->createQueryBuilder('c')
            ->innerJoin('c.typeRdv', 't')->addSelect('t')
            ->innerJoin('c.utilisateur', 'u')->addSelect('u')
            ->leftJoin('u.service', 's')->addSelect('s')
            ->andWhere('c.estActif = true')
            ->andWhere('c.dateDebut BETWEEN :debut AND :fin')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->orderBy('c.dateDebut', 'ASC');

        $this->appliquerFiltresOccupationGlobale($qb, $serviceId, $typeId);

        return $qb->getQuery()->getResult();
    }

    /**
     * Identifiants des créneaux occupés (au moins une réservation ACTIVE) dont le
     * début tombe dans [debut, fin], avec les mêmes filtres que findDansPlageGlobale
     * (US-5.7). Le serializer croise cet ensemble avec la liste des créneaux.
     *
     * `DISTINCT IDENTITY(r.creneau)` sur le côté Reservation filtré par statut ACTIVE :
     * un créneau à [ACTIVE + ANNULEE] ne ressort qu'une fois, un créneau à ANNULEE
     * seule n'y figure pas. Aucune entité hydratée (scalaires), aucun auditeur exposé.
     *
     * @return list<int>
     */
    public function findIdsCreneauxOccupesDansPlage(
        \DateTimeImmutable $debut,
        \DateTimeImmutable $fin,
        ?int $serviceId,
        ?int $typeId,
    ): array {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT IDENTITY(r.creneau)')
            ->from(Reservation::class, 'r')
            ->innerJoin('r.creneau', 'c')
            ->innerJoin('c.typeRdv', 't')
            ->innerJoin('c.utilisateur', 'u')
            ->leftJoin('u.service', 's')
            ->andWhere('r.statut = :statutActif')
            ->andWhere('c.estActif = true')
            ->andWhere('c.dateDebut BETWEEN :debut AND :fin')
            ->setParameter('statutActif', StatutReservation::ACTIVE)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin);

        $this->appliquerFiltresOccupationGlobale($qb, $serviceId, $typeId);

        return array_map(
            static fn (mixed $id): int => (int) $id,
            $qb->getQuery()->getSingleColumnResult(),
        );
    }

    /**
     * Filtres communs (service, type) des requêtes de la vue globale occupé/libre,
     * pour garantir des ensembles « créneaux » et « occupés » strictement cohérents.
     * Suppose des alias `t` (typeRdv) et `s` (service) déjà joints.
     */
    private function appliquerFiltresOccupationGlobale(
        QueryBuilder $qb,
        ?int $serviceId,
        ?int $typeId,
    ): void {
        if ($serviceId !== null) {
            $qb->andWhere('s.id = :serviceId')->setParameter('serviceId', $serviceId);
        }

        if ($typeId !== null) {
            $qb->andWhere('t.id = :typeId')->setParameter('typeId', $typeId);
        }
    }
}
