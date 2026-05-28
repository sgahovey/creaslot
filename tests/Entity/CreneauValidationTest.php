<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Creneau;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Test unitaire validation Entity Creneau (DT-2 niveau 3).
 *
 * Vérifie que la #[Assert\Callback] validerHoraires() :
 * - accepte un Creneau avec dateFin > dateDebut
 * - rejette un Creneau avec dateFin <= dateDebut (violation sur 'dateFin')
 *
 * Pattern : validator standalone (Validation::createValidatorBuilder()).
 * Rapide (pas de boot Kernel) car la validation ne dépend que des attributs
 * PHP de l'Entity, pas du container Symfony.
 *
 * Rappel (DT-2) : ce niveau 3 est un FILET pour les voies non-form. Le flux
 * form (CreneauType, champs mapped:false) est couvert par le niveau 2.
 *
 * Apparition : DT-2 (validation horaire créneau), 28/05/2026.
 *
 * @see Creneau::validerHoraires()
 */
final class CreneauValidationTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function test_creneau_horaires_valides_passe_la_validation(): void
    {
        $creneau = $this->creerCreneau(
            new \DateTimeImmutable('2026-12-01 10:00'),
            new \DateTimeImmutable('2026-12-01 11:00'),
        );

        self::assertCount(
            0,
            $this->violationsHoraires($creneau),
            'Un créneau avec dateFin > dateDebut doit être valide.',
        );
    }

    public function test_creneau_avec_heure_fin_avant_debut_est_rejete(): void
    {
        // Cas DT-2 : 10h00 → 02h00 (le bug exact reproduit en E2E).
        $creneau = $this->creerCreneau(
            new \DateTimeImmutable('2026-12-01 10:00'),
            new \DateTimeImmutable('2026-12-01 02:00'),
        );

        $violations = $this->violationsHoraires($creneau);

        self::assertCount(1, $violations, 'dateFin < dateDebut doit déclencher 1 violation.');
        self::assertSame('dateFin', $violations[0]->getPropertyPath());
        self::assertSame(
            "L'heure de fin doit être postérieure à l'heure de début.",
            $violations[0]->getMessage(),
        );
    }

    public function test_creneau_avec_heure_fin_egale_debut_est_rejete(): void
    {
        // Edge case : durée nulle (10h00 → 10h00), règle A1 stricte.
        $creneau = $this->creerCreneau(
            new \DateTimeImmutable('2026-12-01 10:00'),
            new \DateTimeImmutable('2026-12-01 10:00'),
        );

        $violations = $this->violationsHoraires($creneau);

        self::assertCount(1, $violations, 'dateFin == dateDebut (durée nulle) doit déclencher 1 violation.');
        self::assertSame('dateFin', $violations[0]->getPropertyPath());
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function creerCreneau(
        \DateTimeImmutable $dateDebut,
        \DateTimeImmutable $dateFin,
    ): Creneau {
        // validerHoraires() ne touche que dateDebut/dateFin ; inutile de setter
        // Utilisateur/TypeRdv (aucune contrainte Assert dessus).
        $creneau = new Creneau();
        $creneau->setDateDebut($dateDebut);
        $creneau->setDateFin($dateFin);

        return $creneau;
    }

    /**
     * Ne retient que les violations portant sur dateDebut/dateFin, pour rester
     * robuste si d'autres contraintes apparaissent un jour sur l'Entity.
     *
     * @return list<\Symfony\Component\Validator\ConstraintViolationInterface>
     */
    private function violationsHoraires(Creneau $creneau): array
    {
        $horaires = [];
        foreach ($this->validator->validate($creneau) as $violation) {
            if (in_array($violation->getPropertyPath(), ['dateDebut', 'dateFin'], true)) {
                $horaires[] = $violation;
            }
        }

        return $horaires;
    }
}
