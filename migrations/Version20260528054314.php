<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260528054314 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'US-4.7 : table notification pour la page "Mes notifications" Auditeur. '
            . 'destinataire générique (Utilisateur) + reservation nullable ON DELETE SET NULL '
            . '(RGPD-safe, la notification survit à la suppression de la réservation). '
            . 'Index composite (id_destinataire, lu) pour le COUNT des non-lues (badge).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE notification (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(30) NOT NULL, titre VARCHAR(255) NOT NULL, message LONGTEXT NOT NULL, lu TINYINT NOT NULL, date_creation DATETIME NOT NULL, id_destinataire INT NOT NULL, id_reservation INT DEFAULT NULL, INDEX IDX_BF5476CADD688AE0 (id_destinataire), INDEX IDX_BF5476CA5ADA84A2 (id_reservation), INDEX idx_notification_destinataire_lu (id_destinataire, lu), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CADD688AE0 FOREIGN KEY (id_destinataire) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA5ADA84A2 FOREIGN KEY (id_reservation) REFERENCES reservation (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CADD688AE0');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CA5ADA84A2');
        $this->addSql('DROP TABLE notification');
    }
}
