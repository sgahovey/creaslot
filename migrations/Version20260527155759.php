<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260527155759 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'DT-1 : refacto OneToOne → OneToMany Creneau↔Reservation. '
            . 'Drop UNIQUE INDEX sur reservation.id_creneau pour autoriser '
            . 'plusieurs Reservations par Creneau (1 ACTIVE + N ANNULEE). '
            . 'L\'invariant "1 seule Reservation ACTIVE par Creneau" est '
            . 'préservé applicativement via PESSIMISTIC_WRITE dans '
            . 'ReservationController::enregistrerReservation.';
    }

    public function up(Schema $schema): void
    {
        // ALTER atomique : DROP UNIQUE + ADD INDEX non-unique dans une seule instruction
        // pour ne pas laisser la FK id_creneau sans index intermédiaire (exigence
        // MySQL InnoDB sur les colonnes FK).
        $this->addSql('ALTER TABLE reservation DROP INDEX UNIQ_42C8495527FB222F, ADD INDEX IDX_42C8495527FB222F (id_creneau)');
    }

    public function down(Schema $schema): void
    {
        // ⚠️ WARNING : ce down() ré-impose l'unicité sur id_creneau.
        // Si la BDD contient désormais plusieurs Reservations (ACTIVE + ANNULEE)
        // pointant sur le même Creneau (cas nominal post-DT-1), le ré-ajout du
        // UNIQUE INDEX échouera avec "Duplicate entry". Dans ce cas, nettoyer
        // manuellement les doublons avant de rejouer ce down().
        $this->addSql('ALTER TABLE reservation DROP INDEX IDX_42C8495527FB222F, ADD UNIQUE INDEX UNIQ_42C8495527FB222F (id_creneau)');
    }
}
