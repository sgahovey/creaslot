<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Creneau;
use App\Entity\Reservation;
use App\Entity\TypeRdv;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Enum\TypeNotification;
use App\Repository\NotificationRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration NotificationService — preuve du persist réel (US-4.7).
 *
 * Pattern KernelTestCase + transaction rollback. Le transport mailer est
 * null:// (déjà configuré dans .env, Brevo commenté) → aucun envoi réseau.
 *
 * Motivation : NotificationServiceTest (unitaire) mocke EntityManager (no-op) ;
 * il prouve que le branchement compile et n'altère pas l'email, mais PAS que
 * persist()/flush() écrivent réellement en BDD. Ce test comble cette limite via
 * le vrai NotificationService + EntityManager réel.
 *
 * On teste des méthodes représentatives côté Auditeur (notifierAuditeurReservation)
 * et côté Personnel (notifierPersonnelReservation / notifierPersonnelAnnulationReservation,
 * US-11.1) : le helper persisterNotification() étant commun à toutes les méthodes,
 * sa preuve sur ces méthodes couvre le pattern pour les deux destinataires.
 *
 * @see NotificationService::persisterNotification()
 */
final class NotificationServicePersistTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private NotificationService $service;
    private NotificationRepository $notificationRepository;

    protected function setUp(): void
    {
        self::bootKernel(['environment' => 'test']);
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->service = $container->get(NotificationService::class);
        $this->notificationRepository = $container->get(NotificationRepository::class);

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

    public function test_notifier_auditeur_reservation_persiste_une_notification_in_app(): void
    {
        // GIVEN
        $auditeur = $this->creerUtilisateur(RoleUtilisateur::AUDITEUR);
        $personnel = $this->creerUtilisateur(RoleUtilisateur::PERSONNEL);
        $creneau = $this->creerCreneau($personnel);
        $reservation = $this->creerReservationActive($creneau, $auditeur);

        self::assertSame(0, $this->notificationRepository->countNonLues($auditeur), 'Aucune notification avant l\'appel.');

        // WHEN — vrai service, mailer null:// (aucun envoi réseau).
        $this->service->notifierAuditeurReservation($reservation);

        // THEN
        $paginator = $this->notificationRepository->findByDestinatairePaginated($auditeur, 1);
        self::assertCount(1, $paginator, 'Exactement 1 notification persistée.');

        $notifications = array_values(iterator_to_array($paginator));
        self::assertNotEmpty($notifications);
        $notification = $notifications[0];
        self::assertSame(TypeNotification::CONFIRMATION_RESERVATION, $notification->getType());
        self::assertSame($auditeur, $notification->getDestinataire());
        self::assertSame($reservation, $notification->getReservation());
        self::assertSame('Réservation confirmée', $notification->getTitre());
        self::assertStringContainsString($personnel->getNomComplet(), $notification->getMessage());
        self::assertFalse($notification->isLu(), 'Une nouvelle notification est non lue par défaut.');
    }

    public function test_notifier_personnel_reservation_persiste_une_notification_in_app(): void
    {
        // GIVEN
        $auditeur = $this->creerUtilisateur(RoleUtilisateur::AUDITEUR);
        $personnel = $this->creerUtilisateur(RoleUtilisateur::PERSONNEL);
        $creneau = $this->creerCreneau($personnel);
        $reservation = $this->creerReservationActive($creneau, $auditeur);

        self::assertSame(0, $this->notificationRepository->countNonLues($personnel), 'Aucune notification pour le Personnel avant l\'appel.');

        // WHEN — vrai service, mailer null:// (aucun envoi réseau).
        $this->service->notifierPersonnelReservation($reservation);

        // THEN — la notification va au Personnel (propriétaire du créneau), pas à l'Auditeur.
        $paginator = $this->notificationRepository->findByDestinatairePaginated($personnel, 1);
        self::assertCount(1, $paginator, 'Exactement 1 notification persistée pour le Personnel.');

        $notifications = array_values(iterator_to_array($paginator));
        self::assertNotEmpty($notifications);
        $notification = $notifications[0];
        self::assertSame(TypeNotification::CONFIRMATION_RESERVATION, $notification->getType());
        self::assertSame($personnel, $notification->getDestinataire());
        self::assertSame($reservation, $notification->getReservation());
        self::assertSame('Nouvelle réservation', $notification->getTitre());
        self::assertStringContainsString($auditeur->getNomComplet(), $notification->getMessage());
        self::assertFalse($notification->isLu(), 'Une nouvelle notification est non lue par défaut.');

        // Anti-régression : la notification du Personnel ne doit PAS aller à l'Auditeur.
        self::assertSame(0, $this->notificationRepository->countNonLues($auditeur), 'L\'Auditeur ne reçoit pas la notification destinée au Personnel.');
    }

    public function test_notifier_personnel_annulation_persiste_une_notification_in_app(): void
    {
        // GIVEN
        $auditeur = $this->creerUtilisateur(RoleUtilisateur::AUDITEUR);
        $personnel = $this->creerUtilisateur(RoleUtilisateur::PERSONNEL);
        $creneau = $this->creerCreneau($personnel);
        $reservation = $this->creerReservationActive($creneau, $auditeur);

        self::assertSame(0, $this->notificationRepository->countNonLues($personnel), 'Aucune notification pour le Personnel avant l\'appel.');

        // WHEN — vrai service, mailer null:// (aucun envoi réseau).
        $this->service->notifierPersonnelAnnulationReservation($reservation);

        // THEN — la notification va au Personnel (propriétaire du créneau), pas à l'Auditeur.
        $paginator = $this->notificationRepository->findByDestinatairePaginated($personnel, 1);
        self::assertCount(1, $paginator, 'Exactement 1 notification persistée pour le Personnel.');

        $notifications = array_values(iterator_to_array($paginator));
        self::assertNotEmpty($notifications);
        $notification = $notifications[0];
        self::assertSame(TypeNotification::ANNULATION_RESERVATION, $notification->getType());
        self::assertSame($personnel, $notification->getDestinataire());
        self::assertSame($reservation, $notification->getReservation());
        self::assertSame('Réservation annulée', $notification->getTitre());
        self::assertStringContainsString($auditeur->getNomComplet(), $notification->getMessage());
        self::assertFalse($notification->isLu(), 'Une nouvelle notification est non lue par défaut.');

        // Anti-régression : la notification du Personnel ne doit PAS aller à l'Auditeur.
        self::assertSame(0, $this->notificationRepository->countNonLues($auditeur), 'L\'Auditeur ne reçoit pas la notification destinée au Personnel.');
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function creerUtilisateur(RoleUtilisateur $role): Utilisateur
    {
        $u = new Utilisateur();
        $u->setEmail(strtolower($role->name) . '-' . uniqid() . '@test.local')
          ->setPrenom('Test')
          ->setNom('User')
          ->setRole($role)
          ->setEstActif(true)
          ->setMotDePasseHash('placeholder-not-real');

        $this->entityManager->persist($u);
        $this->entityManager->flush();

        return $u;
    }

    private function creerCreneau(Utilisateur $personnel): Creneau
    {
        $typeRdv = $this->trouverOuCreerTypeRdv();

        $debut = new \DateTimeImmutable('+1 year');
        $creneau = (new Creneau())
            ->setUtilisateur($personnel)
            ->setTypeRdv($typeRdv)
            ->setDateDebut($debut)
            ->setDateFin($debut->modify('+1 hour'))
            ->setEstActif(true);

        $this->entityManager->persist($creneau);
        $this->entityManager->flush();

        return $creneau;
    }

    private function trouverOuCreerTypeRdv(): TypeRdv
    {
        $existant = $this->entityManager->getRepository(TypeRdv::class)->findOneBy([]);
        if ($existant !== null) {
            return $existant;
        }

        $typeRdv = new TypeRdv();
        $typeRdv->setCode('TEST_' . substr(uniqid(), -6))
                ->setLibelle('Test type')
                ->setCouleurHex('#1A3E6F')
                ->setEstActif(true);

        $this->entityManager->persist($typeRdv);
        $this->entityManager->flush();

        return $typeRdv;
    }

    private function creerReservationActive(Creneau $creneau, Utilisateur $auditeur): Reservation
    {
        // Statut ACTIVE par défaut (cf. Reservation::$statut).
        $reservation = (new Reservation())
            ->setCreneau($creneau)
            ->setUtilisateur($auditeur);

        $this->entityManager->persist($reservation);
        $this->entityManager->flush();

        return $reservation;
    }
}
