<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260507041058 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée les 5 tables conformes au MCD officiel : role singulier, date_debut/date_fin sur creneau, id_utilisateur sur toutes les FK, service sans created_at';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE creneau (id INT AUTO_INCREMENT NOT NULL, date_debut DATETIME NOT NULL, date_fin DATETIME NOT NULL, commentaire_auditeur LONGTEXT DEFAULT NULL, date_creation DATETIME NOT NULL, est_actif TINYINT NOT NULL, id_utilisateur INT NOT NULL, id_type_rdv INT NOT NULL, INDEX IDX_F9668B5F50EAE44 (id_utilisateur), INDEX IDX_F9668B5F420F15F2 (id_type_rdv), INDEX idx_creneau_utilisateur_debut (id_utilisateur, date_debut), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reservation (id INT AUTO_INCREMENT NOT NULL, date_reservation DATETIME NOT NULL, commentaire_auditeur LONGTEXT DEFAULT NULL, statut VARCHAR(20) NOT NULL, date_annulation DATETIME DEFAULT NULL, motif_annulation LONGTEXT DEFAULT NULL, id_creneau INT NOT NULL, id_utilisateur INT NOT NULL, UNIQUE INDEX UNIQ_42C8495527FB222F (id_creneau), INDEX IDX_42C8495550EAE44 (id_utilisateur), INDEX idx_reservation_statut (statut), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE service (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(100) NOT NULL, description LONGTEXT DEFAULT NULL, est_actif TINYINT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE type_rdv (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(20) NOT NULL, libelle VARCHAR(50) NOT NULL, couleur_hex VARCHAR(7) NOT NULL, icone VARCHAR(50) DEFAULT NULL, description LONGTEXT DEFAULT NULL, est_actif TINYINT NOT NULL, UNIQUE INDEX UNIQ_type_rdv_code (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE utilisateur (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, mot_de_passe_hash VARCHAR(255) NOT NULL, nom VARCHAR(100) NOT NULL, prenom VARCHAR(100) NOT NULL, role VARCHAR(30) NOT NULL, date_creation DATETIME NOT NULL, derniere_connexion DATETIME DEFAULT NULL, est_actif TINYINT NOT NULL, id_service INT DEFAULT NULL, INDEX IDX_1D1C63B33F0033A2 (id_service), UNIQUE INDEX UNIQ_utilisateur_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE creneau ADD CONSTRAINT FK_F9668B5F50EAE44 FOREIGN KEY (id_utilisateur) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE creneau ADD CONSTRAINT FK_F9668B5F420F15F2 FOREIGN KEY (id_type_rdv) REFERENCES type_rdv (id)');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C8495527FB222F FOREIGN KEY (id_creneau) REFERENCES creneau (id)');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C8495550EAE44 FOREIGN KEY (id_utilisateur) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE utilisateur ADD CONSTRAINT FK_1D1C63B33F0033A2 FOREIGN KEY (id_service) REFERENCES service (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE creneau DROP FOREIGN KEY FK_F9668B5F50EAE44');
        $this->addSql('ALTER TABLE creneau DROP FOREIGN KEY FK_F9668B5F420F15F2');
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY FK_42C8495527FB222F');
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY FK_42C8495550EAE44');
        $this->addSql('ALTER TABLE utilisateur DROP FOREIGN KEY FK_1D1C63B33F0033A2');
        $this->addSql('DROP TABLE creneau');
        $this->addSql('DROP TABLE reservation');
        $this->addSql('DROP TABLE service');
        $this->addSql('DROP TABLE type_rdv');
        $this->addSql('DROP TABLE utilisateur');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
