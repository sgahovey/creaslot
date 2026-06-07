<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Entity\Utilisateur;
use App\Repository\NotificationRepository;
use App\Twig\NotificationExtension;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Test unitaire NotificationExtension (US-4.7).
 *
 * Couvre les 3 branches de compterNonLues() :
 * - 0 si aucun utilisateur connecté (getUser() === null)
 * - 0 si l'utilisateur connecté n'est pas un App\Entity\Utilisateur
 * - le count du repository sinon
 *
 * Pattern : TestCase pur + mocks Security + NotificationRepository (logique
 * purement mockable, pas de boot Kernel nécessaire).
 *
 * @see NotificationExtension::compterNonLues()
 */
final class NotificationExtensionTest extends TestCase
{
    // security/Utilisateur sont de purs stubs (aucune expectation) → createStub.
    // Seul notificationRepository porte des expects() → createMock (vrai mock).
    private Security&Stub $security;
    private NotificationRepository&MockObject $notificationRepository;
    private NotificationExtension $extension;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->notificationRepository = $this->createMock(NotificationRepository::class);

        $this->extension = new NotificationExtension(
            $this->security,
            $this->notificationRepository,
        );
    }

    public function test_compter_non_lues_retourne_zero_si_aucun_utilisateur_connecte(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->notificationRepository->expects(self::never())->method('countNonLues');

        self::assertSame(0, $this->extension->compterNonLues());
    }

    public function test_compter_non_lues_retourne_zero_si_utilisateur_n_est_pas_un_utilisateur(): void
    {
        // Un UserInterface qui n'est PAS App\Entity\Utilisateur.
        $this->security->method('getUser')->willReturn($this->createStub(UserInterface::class));

        $this->notificationRepository->expects(self::never())->method('countNonLues');

        self::assertSame(0, $this->extension->compterNonLues());
    }

    public function test_compter_non_lues_retourne_le_count_du_repository_si_utilisateur(): void
    {
        $utilisateur = $this->createStub(Utilisateur::class);
        $this->security->method('getUser')->willReturn($utilisateur);

        $this->notificationRepository->expects(self::once())
            ->method('countNonLues')
            ->with($utilisateur)
            ->willReturn(7);

        self::assertSame(7, $this->extension->compterNonLues());
    }
}
