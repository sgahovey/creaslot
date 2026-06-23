<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Creneau;
use App\Entity\TypeRdv;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Repository\CreneauRepository;
use App\Service\DateFormatterService;
use App\Service\SlotService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

// setUp à mocks partagés (repository, logger) utilisés comme stubs selon les tests :
// opt-out PHPUnit 13 de la notice « no expectations configured » (clôture DT-3).
#[AllowMockObjectsWithoutExpectations]
final class SlotServiceTest extends TestCase
{
    private CreneauRepository&MockObject $repository;

    private LoggerInterface&MockObject $logger;

    private SlotService $slotService;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(CreneauRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->slotService = new SlotService($this->repository, $this->logger, new DateFormatterService());
    }

    public function test_pas_de_chevauchement_avec_creneau_passe_returns_false(): void
    {
        $user = $this->creerUtilisateur(1);
        $candidat = $this->creerCreneauCandidat($user, '2026-06-10 14:00', '2026-06-10 15:00');

        $this->repository->expects($this->once())
            ->method('findChevauchements')
            ->with(
                $user,
                $candidat->getDateDebut(),
                $candidat->getDateFin(),
                null,
            )
            ->willReturn([]);

        $this->assertFalse($this->slotService->chevaucheAvecExistant($candidat));
    }

    public function test_pas_de_chevauchement_avec_creneau_adjacent_returns_false(): void
    {
        $user = $this->creerUtilisateur(1);
        $candidat = $this->creerCreneauCandidat($user, '2026-06-10 11:00', '2026-06-10 12:00');

        $this->repository->expects($this->once())
            ->method('findChevauchements')
            ->willReturn([]);

        $this->assertFalse($this->slotService->chevaucheAvecExistant($candidat));
    }

    public function test_chevauchement_avec_creneau_identique_returns_true(): void
    {
        $user = $this->creerUtilisateur(1);
        $candidat = $this->creerCreneauCandidat($user, '2026-06-10 10:00', '2026-06-10 11:00');
        $conflit = $this->creerCreneauExistant(50, $user, '2026-06-10 10:00', '2026-06-10 11:00');

        $this->repository->expects($this->once())
            ->method('findChevauchements')
            ->willReturn([$conflit]);

        $this->assertTrue($this->slotService->chevaucheAvecExistant($candidat));
    }

    public function test_chevauchement_avec_creneau_debut_returns_true(): void
    {
        $user = $this->creerUtilisateur(1);
        $candidat = $this->creerCreneauCandidat($user, '2026-06-10 10:30', '2026-06-10 11:30');
        $conflit = $this->creerCreneauExistant(51, $user, '2026-06-10 10:00', '2026-06-10 11:00');

        $this->repository->expects($this->once())
            ->method('findChevauchements')
            ->willReturn([$conflit]);

        $this->assertTrue($this->slotService->chevaucheAvecExistant($candidat));
    }

    public function test_chevauchement_avec_creneau_fin_returns_true(): void
    {
        $user = $this->creerUtilisateur(1);
        $candidat = $this->creerCreneauCandidat($user, '2026-06-10 09:00', '2026-06-10 10:30');
        $conflit = $this->creerCreneauExistant(52, $user, '2026-06-10 10:00', '2026-06-10 12:00');

        $this->repository->expects($this->once())
            ->method('findChevauchements')
            ->willReturn([$conflit]);

        $this->assertTrue($this->slotService->chevaucheAvecExistant($candidat));
    }

    public function test_chevauchement_avec_creneau_pendant_returns_true(): void
    {
        $user = $this->creerUtilisateur(1);
        $candidat = $this->creerCreneauCandidat($user, '2026-06-10 09:00', '2026-06-10 13:00');
        $conflit = $this->creerCreneauExistant(53, $user, '2026-06-10 10:00', '2026-06-10 11:00');

        $this->repository->expects($this->once())
            ->method('findChevauchements')
            ->willReturn([$conflit]);

        $this->assertTrue($this->slotService->chevaucheAvecExistant($candidat));
    }

    public function test_pas_de_chevauchement_avec_creneau_autre_user_returns_false(): void
    {
        $user = $this->creerUtilisateur(1);
        $candidat = $this->creerCreneauCandidat($user, '2026-06-10 10:00', '2026-06-10 11:00');

        $this->repository->expects($this->once())
            ->method('findChevauchements')
            ->with(
                $user,
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn([]);

        $this->assertFalse($this->slotService->chevaucheAvecExistant($candidat));
    }

    public function test_pas_de_chevauchement_avec_creneau_desactive_returns_false(): void
    {
        $user = $this->creerUtilisateur(1);
        $candidat = $this->creerCreneauCandidat($user, '2026-06-10 10:00', '2026-06-10 11:00');

        $this->repository->expects($this->once())
            ->method('findChevauchements')
            ->willReturn([]);

        $this->assertFalse($this->slotService->chevaucheAvecExistant($candidat));
    }

    public function test_modification_exclude_id_pas_de_chevauchement_sur_soi_meme_returns_false(): void
    {
        $user = $this->creerUtilisateur(1);
        $candidat = $this->creerCreneauExistant(99, $user, '2026-06-10 10:00', '2026-06-10 11:00');

        $this->repository->expects($this->once())
            ->method('findChevauchements')
            ->with(
                $user,
                $candidat->getDateDebut(),
                $candidat->getDateFin(),
                99,
            )
            ->willReturn([]);

        $this->assertFalse($this->slotService->chevaucheAvecExistant($candidat));
    }

    public function test_detecte_chevauchements_delegue_au_repository(): void
    {
        $user = $this->creerUtilisateur(1);
        $debut = new \DateTimeImmutable('2026-07-01 09:00');
        $fin = new \DateTimeImmutable('2026-07-01 10:00');
        $conflit = $this->creerCreneauExistant(7, $user, '2026-07-01 09:30', '2026-07-01 10:30');

        $this->repository->expects($this->once())
            ->method('findChevauchements')
            ->with($user, $debut, $fin, 5)
            ->willReturn([$conflit]);

        $this->assertSame([$conflit], $this->slotService->detecteChevauchements($user, $debut, $fin, 5));
    }

    public function test_construire_message_chevauchement_contient_type_et_horaires(): void
    {
        $user = $this->creerUtilisateur(1);
        $conflit = $this->creerCreneauExistant(3, $user, '2026-08-20 14:30', '2026-08-20 15:45');

        $msg = $this->slotService->construireMessageChevauchement($conflit);

        $this->assertStringContainsString('Présentiel', $msg);
        $this->assertStringContainsString('20/08/2026', $msg);
        $this->assertStringContainsString('14:30', $msg);
        $this->assertStringContainsString('15:45', $msg);
    }

    public function test_enregistrer_premier_chevauchement_appelle_logger(): void
    {
        $user = $this->creerUtilisateur(1);
        $candidat = $this->creerCreneauCandidat($user, '2026-06-10 10:00', '2026-06-10 11:00');
        $conflit = $this->creerCreneauExistant(8, $user, '2026-06-10 10:15', '2026-06-10 11:15');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Chevauchement de créneau détecté',
                $this->callback(static function (array $ctx): bool {
                    return isset($ctx['contexte'], $ctx['creneau_conflit_id']);
                }),
            );

        $this->slotService->enregistrerPremierChevauchement($candidat, [$conflit], 'test_unitaire');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function creerUtilisateur(int $id): Utilisateur
    {
        $u = new Utilisateur();
        $u->setRole(RoleUtilisateur::PERSONNEL);
        $u->setEstActif(true);
        $p = new \ReflectionProperty(Utilisateur::class, 'id');
        $p->setValue($u, $id);

        return $u;
    }

    private function creerTypeRdv(string $libelle = 'Présentiel'): TypeRdv
    {
        $t = new TypeRdv();
        $t->setCode('PRES');
        $t->setLibelle($libelle);
        $t->setCouleurHex('#00AA00');

        return $t;
    }

    private function creerCreneauCandidat(Utilisateur $user, string $debut, string $fin): Creneau
    {
        $c = new Creneau();
        $c->setUtilisateur($user);
        $c->setTypeRdv($this->creerTypeRdv());
        $c->setDateDebut(new \DateTimeImmutable($debut));
        $c->setDateFin(new \DateTimeImmutable($fin));

        return $c;
    }

    private function creerCreneauExistant(int $id, Utilisateur $user, string $debut, string $fin): Creneau
    {
        $c = $this->creerCreneauCandidat($user, $debut, $fin);
        $p = new \ReflectionProperty(Creneau::class, 'id');
        $p->setValue($c, $id);

        return $c;
    }
}
