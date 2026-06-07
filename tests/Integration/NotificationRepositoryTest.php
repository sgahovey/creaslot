<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Notification;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Enum\TypeNotification;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration NotificationRepository (US-4.7).
 *
 * Pattern KernelTestCase + transaction rollback en tearDown (3e usage du
 * pattern, après DT-1 et le hotfix DT-1 résiduel). Autonome : crée ses propres
 * entités, sans dépendre des fixtures en BDD test.
 *
 * Couvre :
 * - findByDestinatairePaginated : tri DESC + pagination
 * - countNonLues : ne compte que les non-lues du destinataire
 * - marquerToutesLues : UPDATE ciblé (n'affecte pas les autres utilisateurs)
 *
 * @see NotificationRepository
 */
final class NotificationRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private NotificationRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel(['environment' => 'test']);
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(NotificationRepository::class);

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

    public function test_find_by_destinataire_paginated_retourne_les_notifications_triees_desc(): void
    {
        $destinataire = $this->creerAuditeur();

        $this->creerNotification($destinataire, TypeNotification::CONFIRMATION_RESERVATION, '-2 hours');
        $this->creerNotification($destinataire, TypeNotification::ANNULATION_RESERVATION, '-1 hour');
        $this->creerNotification($destinataire, TypeNotification::RAPPEL_J1, 'now');

        $paginator = $this->repository->findByDestinatairePaginated($destinataire, 1);

        self::assertInstanceOf(Paginator::class, $paginator);
        self::assertSame(3, count($paginator));

        $notifications = array_values(iterator_to_array($paginator));
        self::assertSame(TypeNotification::RAPPEL_J1, $notifications[0]->getType(), 'La plus récente en premier (DESC).');
        self::assertSame(TypeNotification::ANNULATION_RESERVATION, $notifications[1]->getType());
        self::assertSame(TypeNotification::CONFIRMATION_RESERVATION, $notifications[2]->getType());
    }

    public function test_count_non_lues_ne_compte_que_les_non_lues(): void
    {
        $destinataire = $this->creerAuditeur();

        $this->creerNotification($destinataire, TypeNotification::CONFIRMATION_RESERVATION, 'now', lu: false);
        $this->creerNotification($destinataire, TypeNotification::ANNULATION_RESERVATION, 'now', lu: false);
        $this->creerNotification($destinataire, TypeNotification::RAPPEL_J1, 'now', lu: true);

        self::assertSame(2, $this->repository->countNonLues($destinataire));
    }

    public function test_marquer_toutes_lues_ne_touche_que_les_non_lues_du_destinataire(): void
    {
        $destinataire = $this->creerAuditeur();
        $autre = $this->creerAuditeur();

        $this->creerNotification($destinataire, TypeNotification::CONFIRMATION_RESERVATION, 'now', lu: false);
        $this->creerNotification($destinataire, TypeNotification::ANNULATION_RESERVATION, 'now', lu: false);
        $this->creerNotification($destinataire, TypeNotification::RAPPEL_J1, 'now', lu: true);
        $this->creerNotification($autre, TypeNotification::CONFIRMATION_RESERVATION, 'now', lu: false);

        $count = $this->repository->marquerToutesLues($destinataire);

        self::assertSame(2, $count, '2 non-lues du destinataire mises à jour (la 3e était déjà lue).');
        self::assertSame(0, $this->repository->countNonLues($destinataire));
        self::assertSame(
            1,
            $this->repository->countNonLues($autre),
            "Les notifications d'un autre utilisateur ne doivent pas être touchées.",
        );
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function creerAuditeur(): Utilisateur
    {
        $u = new Utilisateur();
        $u->setEmail('notif-' . uniqid() . '@test.local')
          ->setPrenom('Test')
          ->setNom('Auditeur')
          ->setRole(RoleUtilisateur::AUDITEUR)
          ->setEstActif(true)
          ->setMotDePasseHash('placeholder-not-real');

        $this->entityManager->persist($u);
        $this->entityManager->flush();

        return $u;
    }

    private function creerNotification(
        Utilisateur $destinataire,
        TypeNotification $type,
        string $dateExpression,
        bool $lu = false,
    ): Notification {
        $notification = (new Notification())
            ->setDestinataire($destinataire)
            ->setType($type)
            ->setTitre('Test ' . $type->value)
            ->setMessage('Message de test')
            ->setLu($lu);

        // Forcer dateCreation AVANT persist (un seul flush, date déterministe pour
        // le test de tri). dateCreation n'a pas de setter public (défini en __construct).
        if ($dateExpression !== 'now') {
            $ref = new \ReflectionProperty(Notification::class, 'dateCreation');
            $ref->setValue($notification, new \DateTimeImmutable($dateExpression));
        }

        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        return $notification;
    }
}
