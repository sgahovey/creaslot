<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Creneau;
use App\Entity\TypeRdv;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Service\OccupationCalendarSerializer;
use PHPUnit\Framework\TestCase;

/**
 * Test unitaire (sans BDD) du serializer de la vue globale occupé/libre (US-5.7).
 *
 * Vérifie le titre (personnel + type), l'occupation dérivée de l'ensemble d'ids,
 * l'état cohérent, la couleur, le format ATOM, et surtout l'ABSENCE de toute
 * donnée d'auditeur (RGPD, minimisation).
 */
final class OccupationCalendarSerializerTest extends TestCase
{
    private OccupationCalendarSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new OccupationCalendarSerializer();
    }

    public function test_titre_compose_le_personnel_et_le_type(): void
    {
        $creneau = $this->creerCreneau(10, 'Visioconférence', '#0d6efd', 'Marie', 'Dupont');

        $evenements = $this->serializer->toCalendarEvents([$creneau], []);

        self::assertCount(1, $evenements);
        self::assertSame('Marie Dupont · Visioconférence', $evenements[0]['title']);
        self::assertSame('Marie Dupont', $evenements[0]['extendedProps']['personnelNom']);
        self::assertSame('Visioconférence', $evenements[0]['extendedProps']['typeRdv']);
    }

    public function test_occupe_vrai_si_id_dans_l_ensemble(): void
    {
        $creneau = $this->creerCreneau(42, 'Présentiel', '#198754', 'Jean', 'Martin');

        $evenements = $this->serializer->toCalendarEvents([$creneau], [42]);

        self::assertTrue($evenements[0]['extendedProps']['occupe']);
        self::assertSame('Occupé', $evenements[0]['extendedProps']['etat']);
    }

    public function test_libre_si_id_absent_de_l_ensemble(): void
    {
        $creneau = $this->creerCreneau(7, 'Téléphone', '#fd7e14', 'Léa', 'Bernard');

        $evenements = $this->serializer->toCalendarEvents([$creneau], [1, 2, 3]);

        self::assertFalse($evenements[0]['extendedProps']['occupe']);
        self::assertSame('Libre', $evenements[0]['extendedProps']['etat']);
    }

    public function test_couleur_et_dates_au_format_atom(): void
    {
        $creneau = $this->creerCreneau(5, 'Visio', '#abcdef', 'Paul', 'Durand');

        $evenement = $this->serializer->toCalendarEvents([$creneau], [])[0];

        self::assertSame('#abcdef', $evenement['color']);
        self::assertSame($creneau->getDateDebut()->format(\DateTimeInterface::ATOM), $evenement['start']);
        self::assertSame($creneau->getDateFin()->format(\DateTimeInterface::ATOM), $evenement['end']);
    }

    public function test_aucune_donnee_d_auditeur_n_est_exposee(): void
    {
        $creneau = $this->creerCreneau(99, 'Présentiel', '#6c757d', 'Sophie', 'Petit');

        $extendedProps = $this->serializer->toCalendarEvents([$creneau], [99])[0]['extendedProps'];

        // RGPD : la vue globale ne révèle jamais qui a réservé.
        self::assertArrayNotHasKey('auditeurNom', $extendedProps);
        self::assertArrayNotHasKey('commentaire', $extendedProps);
        self::assertArrayNotHasKey('motifAuditeur', $extendedProps);
    }

    private function creerCreneau(
        int $id,
        string $libelleType,
        string $couleurHex,
        string $prenomPersonnel,
        string $nomPersonnel,
    ): Creneau {
        $typeRdv = (new TypeRdv())
            ->setCode('T' . $id)
            ->setLibelle($libelleType)
            ->setCouleurHex($couleurHex);

        $personnel = (new Utilisateur())
            ->setEmail('p' . $id . '@test.local')
            ->setPrenom($prenomPersonnel)
            ->setNom($nomPersonnel)
            ->setRole(RoleUtilisateur::PERSONNEL)
            ->setMotDePasseHash('placeholder-not-real');

        $debut = new \DateTimeImmutable('2026-06-10 09:00:00');

        $creneau = (new Creneau())
            ->setUtilisateur($personnel)
            ->setTypeRdv($typeRdv)
            ->setDateDebut($debut)
            ->setDateFin($debut->modify('+1 hour'))
            ->setEstActif(true);

        $prop = new \ReflectionProperty(Creneau::class, 'id');
        $prop->setValue($creneau, $id);

        return $creneau;
    }
}
