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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration DT-1 — Non-régression "ré-réservation après annulation".
 *
 * Fige le scénario qui causait HTTP 500 avant le refacto OneToMany :
 *   1. Auditeur réserve un Creneau (Reservation ACTIVE)
 *   2. Auditeur annule (Reservation → ANNULEE, soft-delete)
 *   3. Le MÊME Creneau est ré-réservé → DOIT RÉUSSIR
 *
 * Avant DT-1 (OneToOne avec UNIQUE INDEX UNIQ_42C8495527FB222F sur id_creneau) :
 * l'étape 3 échouait avec "Duplicate entry 'X' for key 'reservation.UNIQ_...'"
 * (HTTP 500). Après DT-1 (OneToMany, INDEX non-unique), elle réussit ; le
 * Creneau a alors 2 Reservations (1 ANNULEE historique + 1 ACTIVE actuelle).
 *
 * L'invariant "1 seule Reservation ACTIVE par Creneau" est préservé
 * applicativement via PESSIMISTIC_WRITE dans
 * ReservationController::enregistrerReservation (hors scope ici).
 *
 * Pattern : KernelTestCase + EntityManager + transaction rollback en tearDown,
 * pour isoler le test sans nécessiter une BDD test séparée (le projet partage
 * la même BDD entre dev et test ; le rollback garantit qu'aucune entité créée
 * ici ne survit au test).
 *
 * Apparition : refacto DT-1 (OneToOne → OneToMany), 27/05/2026.
 */
final class ReservationRereservationApresAnnulationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        // Forcer environment='test' explicitement : la directive <server> de
        // phpunit.dist.xml ne propage pas toujours APP_ENV à Symfony selon les
        // versions. Cet override garantit que le kernel boote sur la BDD test
        // (creaslot_test) et expose le test.service_container.
        self::bootKernel(['environment' => 'test']);
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $this->entityManager->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        $this->entityManager->close();
        parent::tearDown();
    }

    public function test_un_creneau_peut_etre_reserve_apres_annulation_de_la_reservation_precedente(): void
    {
        $personnel = $this->creerPersonnel();
        $auditeur = $this->creerAuditeur();
        $typeRdv = $this->trouverOuCreerTypeRdv();
        $creneau = $this->creerCreneau($personnel, $typeRdv);

        $this->entityManager->flush();

        // ─ ÉTAPE 1 — Première réservation ────────────────────────────────
        $reservation1 = (new Reservation())
            ->setCreneau($creneau)
            ->setUtilisateur($auditeur)
            ->setStatut(StatutReservation::ACTIVE);
        $this->entityManager->persist($reservation1);
        $this->entityManager->flush();
        // Doctrine ne synchronise pas automatiquement le côté inverse OneToMany
        // après persist+flush — il faut refresh() pour repeupler la Collection.
        $this->entityManager->refresh($creneau);

        self::assertTrue($creneau->isReserve());
        self::assertSame($reservation1, $creneau->getReservationActive());

        // ─ ÉTAPE 2 — Annulation (soft-delete) ────────────────────────────
        $reservation1->annuler('Test DT-1 : annulation pour smoke');
        $this->entityManager->flush();
        $this->entityManager->refresh($creneau);

        self::assertFalse(
            $creneau->isReserve(),
            'Le créneau doit redevenir disponible après annulation.',
        );
        self::assertNull(
            $creneau->getReservationActive(),
            'getReservationActive() doit retourner null une fois la résa annulée.',
        );

        // ─ ÉTAPE 3 — Ré-réservation du MÊME Creneau (cœur DT-1) ──────────
        // AVANT DT-1 : UNIQUE constraint violation → HTTP 500 reproductible.
        // APRÈS DT-1 : doit réussir sans exception.
        $reservation2 = (new Reservation())
            ->setCreneau($creneau)
            ->setUtilisateur($auditeur)
            ->setStatut(StatutReservation::ACTIVE);
        $this->entityManager->persist($reservation2);
        $this->entityManager->flush();
        $this->entityManager->refresh($creneau);

        // ─ ASSERTIONS finales — 2 Reservations cohabitent ────────────────
        self::assertCount(
            2,
            $creneau->getReservations(),
            'Le créneau doit avoir 2 Reservations : 1 ANNULEE historique + 1 ACTIVE actuelle.',
        );
        self::assertSame(StatutReservation::ACTIVE, $reservation2->getStatut());
        self::assertTrue($creneau->isReserve());
        self::assertSame(
            $reservation2,
            $creneau->getReservationActive(),
            'getReservationActive() doit cibler la nouvelle ACTIVE, pas l\'ancienne ANNULEE.',
        );
        self::assertSame($auditeur, $creneau->getAuditeurReservation());
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers — création d'entités à la volée (indépendant des fixtures dev)
    // ─────────────────────────────────────────────────────────────────────

    private function creerPersonnel(): Utilisateur
    {
        $service = new Service();
        $service->setNom('Service Test DT-1 ' . uniqid())->setEstActif(true);
        $this->entityManager->persist($service);

        $personnel = new Utilisateur();
        $personnel->setEmail('dt1-personnel-' . uniqid() . '@test.local')
                  ->setPrenom('Marie')
                  ->setNom('TestDT1')
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
        $auditeur->setEmail('dt1-auditeur-' . uniqid() . '@test.local')
                 ->setPrenom('Xavier')
                 ->setNom('TestDT1')
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
        $typeRdv->setCode('TEST_DT1_' . substr(uniqid(), -6))
                ->setLibelle('Test DT-1')
                ->setCouleurHex('#1A3E6F')
                ->setEstActif(true);
        $this->entityManager->persist($typeRdv);

        return $typeRdv;
    }

    private function creerCreneau(Utilisateur $personnel, TypeRdv $typeRdv): Creneau
    {
        $dateDebut = new \DateTimeImmutable('+1 year');
        $dateFin = $dateDebut->modify('+1 hour');

        $creneau = new Creneau();
        $creneau->setUtilisateur($personnel)
                ->setTypeRdv($typeRdv)
                ->setDateDebut($dateDebut)
                ->setDateFin($dateFin)
                ->setEstActif(true);
        $this->entityManager->persist($creneau);

        return $creneau;
    }
}
