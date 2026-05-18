<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DateFormatterService;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires du DateFormatterService.
 *
 * Service stateless → tests purs sans mocks ni fixtures Doctrine.
 *
 * Couverture : 4 tests couvrant la conversion UTC → Réunion, la stabilité
 * sur date déjà en Réunion, les minutes à zéro, et la compatibilité
 * \DateTime mutable vs \DateTimeImmutable.
 */
final class DateFormatterServiceTest extends TestCase
{
    private DateFormatterService $service;

    protected function setUp(): void
    {
        $this->service = new DateFormatterService();
    }

    public function test_pourSujetEmail_convertit_correctement_UTC_en_heure_reunion(): void
    {
        // 26/05/2026 10:00 UTC = 26/05/2026 14:00 Réunion (UTC+4)
        $date = new \DateTimeImmutable('2026-05-26 10:00:00', new \DateTimeZone('UTC'));

        $result = $this->service->pourSujetEmail($date);

        self::assertSame('26/05/2026 à 14h00', $result);
    }

    public function test_pourSujetEmail_inchange_si_date_deja_en_heure_reunion(): void
    {
        // 19/05/2026 09:30 Réunion → format direct sans conversion
        $date = new \DateTimeImmutable('2026-05-19 09:30:00', new \DateTimeZone('Indian/Reunion'));

        $result = $this->service->pourSujetEmail($date);

        self::assertSame('19/05/2026 à 09h30', $result);
    }

    public function test_pourSujetEmail_formate_correctement_minutes_a_zero(): void
    {
        $date = new \DateTimeImmutable('2026-12-31 23:00:00', new \DateTimeZone('Indian/Reunion'));

        $result = $this->service->pourSujetEmail($date);

        self::assertSame('31/12/2026 à 23h00', $result);
    }

    public function test_pourSujetEmail_compatible_avec_DateTime_mutable(): void
    {
        // Test défensif : le service accepte \DateTimeInterface (donc compatible
        // avec \DateTime mutable ET \DateTimeImmutable). Dans les fixtures et
        // l'entité Creneau, on utilise systématiquement \DateTimeImmutable —
        // ce test garantit qu'un futur usage avec \DateTime mutable ne casse pas.
        $date = new \DateTime('2026-05-19 09:00:00', new \DateTimeZone('Indian/Reunion'));

        $result = $this->service->pourSujetEmail($date);

        self::assertSame('19/05/2026 à 09h00', $result);
    }
}
