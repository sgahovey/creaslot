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
 * Couverture : pour chaque format (pourSujetEmail, pourDate, pourHeure,
 * pourHeureCompacte), la conversion UTC → Réunion, la stabilité sur date
 * déjà en Réunion et la compatibilité \DateTime mutable vs \DateTimeImmutable.
 * pourHeure/pourHeureCompacte vérifient en plus le zéro initial avant 10h et
 * la distinction des séparateurs (":" vs "h").
 */
final class DateFormatterServiceTest extends TestCase
{
    private DateFormatterService $service;

    protected function setUp(): void
    {
        $this->service = new DateFormatterService();
    }

    public function test_pour_sujet_email_convertit_correctement_ut_c_en_heure_reunion(): void
    {
        // 26/05/2026 10:00 UTC = 26/05/2026 14:00 Réunion (UTC+4)
        $date = new \DateTimeImmutable('2026-05-26 10:00:00', new \DateTimeZone('UTC'));

        $result = $this->service->pourSujetEmail($date);

        self::assertSame('26/05/2026 à 14h00', $result);
    }

    public function test_pour_sujet_email_inchange_si_date_deja_en_heure_reunion(): void
    {
        // 19/05/2026 09:30 Réunion → format direct sans conversion
        $date = new \DateTimeImmutable('2026-05-19 09:30:00', new \DateTimeZone('Indian/Reunion'));

        $result = $this->service->pourSujetEmail($date);

        self::assertSame('19/05/2026 à 09h30', $result);
    }

    public function test_pour_sujet_email_formate_correctement_minutes_a_zero(): void
    {
        $date = new \DateTimeImmutable('2026-12-31 23:00:00', new \DateTimeZone('Indian/Reunion'));

        $result = $this->service->pourSujetEmail($date);

        self::assertSame('31/12/2026 à 23h00', $result);
    }

    public function test_pour_sujet_email_compatible_avec_date_time_mutable(): void
    {
        // Test défensif : le service accepte \DateTimeInterface (donc compatible
        // avec \DateTime mutable ET \DateTimeImmutable). Dans les fixtures et
        // l'entité Creneau, on utilise systématiquement \DateTimeImmutable —
        // ce test garantit qu'un futur usage avec \DateTime mutable ne casse pas.
        $date = new \DateTime('2026-05-19 09:00:00', new \DateTimeZone('Indian/Reunion'));

        $result = $this->service->pourSujetEmail($date);

        self::assertSame('19/05/2026 à 09h00', $result);
    }

    public function test_pour_date_convertit_correctement_ut_c_en_heure_reunion(): void
    {
        // 26/05/2026 10:00 UTC = 26/05/2026 (14:00) Réunion (UTC+4)
        $date = new \DateTimeImmutable('2026-05-26 10:00:00', new \DateTimeZone('UTC'));

        $result = $this->service->pourDate($date);

        self::assertSame('26/05/2026', $result);
    }

    public function test_pour_date_inchange_si_date_deja_en_heure_reunion(): void
    {
        $date = new \DateTimeImmutable('2026-05-19 09:30:00', new \DateTimeZone('Indian/Reunion'));

        $result = $this->service->pourDate($date);

        self::assertSame('19/05/2026', $result);
    }

    public function test_pour_date_compatible_avec_date_time_mutable(): void
    {
        $date = new \DateTime('2026-05-19 09:00:00', new \DateTimeZone('Indian/Reunion'));

        $result = $this->service->pourDate($date);

        self::assertSame('19/05/2026', $result);
    }

    public function test_pour_heure_convertit_correctement_ut_c_en_heure_reunion(): void
    {
        // 26/05/2026 10:00 UTC = 14:00 Réunion (UTC+4)
        $date = new \DateTimeImmutable('2026-05-26 10:00:00', new \DateTimeZone('UTC'));

        $result = $this->service->pourHeure($date);

        self::assertSame('14:00', $result);
    }

    public function test_pour_heure_inchange_si_date_deja_en_heure_reunion(): void
    {
        $date = new \DateTimeImmutable('2026-05-19 08:30:00', new \DateTimeZone('Indian/Reunion'));

        $result = $this->service->pourHeure($date);

        self::assertSame('08:30', $result);
    }

    public function test_pour_heure_conserve_le_zero_initial_avant_dix_heures(): void
    {
        // Heure < 10h : fige le zéro initial et le séparateur deux-points
        $date = new \DateTimeImmutable('2026-05-19 08:30:00', new \DateTimeZone('Indian/Reunion'));

        $result = $this->service->pourHeure($date);

        self::assertSame('08:30', $result);
    }

    public function test_pour_heure_compatible_avec_date_time_mutable(): void
    {
        $date = new \DateTime('2026-05-19 09:00:00', new \DateTimeZone('Indian/Reunion'));

        $result = $this->service->pourHeure($date);

        self::assertSame('09:00', $result);
    }

    public function test_pour_heure_compacte_convertit_correctement_ut_c_en_heure_reunion(): void
    {
        // 26/05/2026 10:00 UTC = 14h00 Réunion (UTC+4)
        $date = new \DateTimeImmutable('2026-05-26 10:00:00', new \DateTimeZone('UTC'));

        $result = $this->service->pourHeureCompacte($date);

        self::assertSame('14h00', $result);
    }

    public function test_pour_heure_compacte_inchange_si_date_deja_en_heure_reunion(): void
    {
        $date = new \DateTimeImmutable('2026-05-19 08:30:00', new \DateTimeZone('Indian/Reunion'));

        $result = $this->service->pourHeureCompacte($date);

        self::assertSame('08h30', $result);
    }

    public function test_pour_heure_compacte_conserve_le_zero_initial_avant_dix_heures(): void
    {
        // Heure < 10h : fige le zéro initial et le séparateur "h"
        $date = new \DateTimeImmutable('2026-05-19 08:30:00', new \DateTimeZone('Indian/Reunion'));

        $result = $this->service->pourHeureCompacte($date);

        self::assertSame('08h30', $result);
    }

    public function test_pour_heure_compacte_compatible_avec_date_time_mutable(): void
    {
        $date = new \DateTime('2026-05-19 09:00:00', new \DateTimeZone('Indian/Reunion'));

        $result = $this->service->pourHeureCompacte($date);

        self::assertSame('09h00', $result);
    }

    public function test_pour_heure_et_pour_heure_compacte_different_par_le_separateur(): void
    {
        // Même instant 08:30 Réunion : séparateur deux-points vs "h"
        $date = new \DateTimeImmutable('2026-05-19 08:30:00', new \DateTimeZone('Indian/Reunion'));

        self::assertSame('08:30', $this->service->pourHeure($date));
        self::assertSame('08h30', $this->service->pourHeureCompacte($date));
    }
}
