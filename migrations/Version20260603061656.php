<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260603061656 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE journal_admin (id INT AUTO_INCREMENT NOT NULL, date_action DATETIME NOT NULL, type_action VARCHAR(40) NOT NULL, acteur_id INT NOT NULL, acteur_libelle VARCHAR(201) NOT NULL, cible_id INT DEFAULT NULL, cible_libelle VARCHAR(201) DEFAULT NULL, details LONGTEXT DEFAULT NULL, INDEX idx_journal_admin_date (date_action), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE journal_admin');
    }
}
