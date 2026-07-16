<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Creneau;
use App\Entity\Reservation;
use App\Entity\Service;
use App\Entity\TypeRdv;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Enum\StatutReservation;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration ReservationRepository::existeReservationActiveEnChevauchement (DT-39).
 *
 * Comble la lacune tracée en DT-39 : la méthode était exercée en production (via
 * ReservationService::aReservationChevauchante appelée par le contrôleur) mais sans
 * test dédié assertant le rejet du chevauchement de deux réservations du MÊME auditeur.
 *
 * On vérifie la sémantique métier (résultat booléen), pas seulement la validité DQL :
 * chevauchement réel → vrai ; absence de chevauchement, adjacence bord à bord,
 * réservation ANNULEE, et réservation d'un autre auditeur → faux.
 *
 * Autonome : crée ses propres entités en transaction (rollback en tearDown), sans
 * dépendre des fixtures chargées en BDD test.
 *
 * @see ReservationRepository::existeReservationActiveEnChevauchement()
 */
final class ReservationRepositoryQueriesTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ReservationRepository $reservationRepository;

    private Utilisateur $personnel;
    private TypeRdv $typeRdv;
    private Utilisateur $auditeur;
    private \DateTimeImmutable $debut;
    private \DateTimeImmutable $fin;

    protected function setUp(): void
    {
        self::bootKernel(['environment' => 'test']);
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->reservationRepository = $container->get(ReservationRepository::class);

        $this->entityManager->beginTransaction();

        // Scénario de base : l'auditeur a une réservation ACTIVE sur un créneau [9h00-10h00].
        $this->personnel = $this->creerPersonnel();
        $this->typeRdv = $this->trouverOuCreerTypeRdv();
        $this->auditeur = $this->creerAuditeur();

        $this->debut = (new \DateTimeImmutable('+1 year'))->setTime(9, 0);
        $this->fin = $this->debut->modify('+1 hour');

        $creneau = $this->creerCreneau($this->personnel, $this->typeRdv, $this->debut, $this->fin);
        $this->creerReservation($this->auditeur, $creneau, StatutReservation::ACTIVE);

        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        $this->entityManager->close();
        parent::tearDown();
    }

    public function test_chevauchement_partiel_avec_reservation_active_retourne_vrai(): void
    {
        // [9h30-10h30] recouvre [9h00-10h00].
        self::assertTrue($this->reservationRepository->existeReservationActiveEnChevauchement(
            $this->auditeur,
            $this->debut->setTime(9, 30),
            $this->debut->setTime(10, 30),
        ));
    }

    public function test_plage_identique_retourne_vrai(): void
    {
        self::assertTrue($this->reservationRepository->existeReservationActiveEnChevauchement(
            $this->auditeur,
            $this->debut,
            $this->fin,
        ));
    }

    public function test_absence_de_chevauchement_retourne_faux(): void
    {
        // [11h00-12h00] ne recouvre pas [9h00-10h00].
        self::assertFalse($this->reservationRepository->existeReservationActiveEnChevauchement(
            $this->auditeur,
            $this->debut->setTime(11, 0),
            $this->debut->setTime(12, 0),
        ));
    }

    public function test_adjacence_bord_a_bord_nest_pas_un_chevauchement(): void
    {
        // La plage demandée commence exactement à la fin du créneau réservé (10h00).
        // Intersection stricte (dateDebut < fin AND dateFin > debut) → pas de chevauchement.
        self::assertFalse($this->reservationRepository->existeReservationActiveEnChevauchement(
            $this->auditeur,
            $this->debut->setTime(10, 0),
            $this->debut->setTime(11, 0),
        ));
    }

    public function test_reservation_annulee_est_ignoree(): void
    {
        // Un auditeur dont l'UNIQUE réservation chevauchante est ANNULEE ne bloque pas.
        $auditeur = $this->creerAuditeur();
        $creneau = $this->creerCreneau($this->personnel, $this->typeRdv, $this->debut, $this->fin);
        $this->creerReservation($auditeur, $creneau, StatutReservation::ANNULEE);
        $this->entityManager->flush();

        self::assertFalse($this->reservationRepository->existeReservationActiveEnChevauchement(
            $auditeur,
            $this->debut->setTime(9, 30),
            $this->debut->setTime(10, 30),
        ));
    }

    public function test_reservation_d_un_autre_auditeur_est_ignoree(): void
    {
        // Le créneau est réservé (ACTIVE) par $this->auditeur ; un AUTRE auditeur, lui,
        // n'a aucune réservation → faux (la méthode raisonne par auditeur, pas par créneau).
        $autre = $this->creerAuditeur();
        $this->entityManager->flush();

        self::assertFalse($this->reservationRepository->existeReservationActiveEnChevauchement(
            $autre,
            $this->debut->setTime(9, 30),
            $this->debut->setTime(10, 30),
        ));
    }

    // ---------------------------------------------------------------------
    // Helpers — entités jetables (transaction rollback en tearDown)
    // ---------------------------------------------------------------------

    private function creerPersonnel(): Utilisateur
    {
        $service = new Service();
        $service->setNom('Service Test Resa ' . uniqid())->setEstActif(true);
        $this->entityManager->persist($service);

        $personnel = new Utilisateur();
        $personnel->setEmail('resa-personnel-' . uniqid() . '@test.local')
                  ->setPrenom('Marie')
                  ->setNom('TestResa')
                  ->setRole(RoleUtilisateur::PERSONNEL)
                  ->setEstActif(true)
                  ->setService($service)
                  ->setMotDePasseHash('placeholder-not-real');
        $this->entityManager->persist($personnel);

        return $personnel;
    }

    private function creerAuditeur(): Utilisateur
    {
        $auditeur = new Utilisateur();
        $auditeur->setEmail('resa-auditeur-' . uniqid() . '@test.local')
                 ->setPrenom('Xavier')
                 ->setNom('TestResa')
                 ->setRole(RoleUtilisateur::AUDITEUR)
                 ->setEstActif(true)
                 ->setMotDePasseHash('placeholder-not-real');
        $this->entityManager->persist($auditeur);

        return $auditeur;
    }

    private function trouverOuCreerTypeRdv(): TypeRdv
    {
        $existant = $this->entityManager->getRepository(TypeRdv::class)->findOneBy([]);
        if ($existant !== null) {
            return $existant;
        }

        $typeRdv = new TypeRdv();
        $typeRdv->setCode('TEST_RESA_' . substr(uniqid(), -6))
                ->setLibelle('Test Resa')
                ->setCouleurHex('#1A3E6F')
                ->setEstActif(true);
        $this->entityManager->persist($typeRdv);

        return $typeRdv;
    }

    private function creerCreneau(
        Utilisateur $personnel,
        TypeRdv $typeRdv,
        \DateTimeImmutable $debut,
        \DateTimeImmutable $fin,
    ): Creneau {
        $creneau = (new Creneau())
            ->setUtilisateur($personnel)
            ->setTypeRdv($typeRdv)
            ->setDateDebut($debut)
            ->setDateFin($fin)
            ->setEstActif(true);
        $this->entityManager->persist($creneau);

        return $creneau;
    }

    private function creerReservation(Utilisateur $auditeur, Creneau $creneau, StatutReservation $statut): Reservation
    {
        $reservation = (new Reservation())
            ->setCreneau($creneau)
            ->setUtilisateur($auditeur)
            ->setStatut($statut);
        $this->entityManager->persist($reservation);

        return $reservation;
    }
}
