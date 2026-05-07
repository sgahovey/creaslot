<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260506145509 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée les 5 tables métier : utilisateur, service, type_rdv, creneau, reservation avec FK, index et contraintes UNIQUE.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE creneau (id INT AUTO_INCREMENT NOT NULL, debut_at DATETIME NOT NULL, fin_at DATETIME NOT NULL, statut VARCHAR(20) NOT NULL, est_actif TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, id_personnel INT NOT NULL, id_service INT NOT NULL, id_type_rdv INT NOT NULL, INDEX IDX_F9668B5F26894FF9 (id_personnel), INDEX IDX_F9668B5F3F0033A2 (id_service), INDEX IDX_F9668B5F420F15F2 (id_type_rdv), INDEX idx_creneau_personnel_debut (id_personnel, debut_at), INDEX idx_creneau_statut (statut), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reservation (id INT AUTO_INCREMENT NOT NULL, commentaire LONGTEXT DEFAULT NULL, statut VARCHAR(20) NOT NULL, motif_annulation LONGTEXT DEFAULT NULL, annulee_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, id_creneau INT NOT NULL, id_auditeur INT NOT NULL, UNIQUE INDEX UNIQ_42C8495527FB222F (id_creneau), INDEX IDX_42C84955D6AD9786 (id_auditeur), INDEX idx_reservation_statut (statut), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE service (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(100) NOT NULL, description LONGTEXT DEFAULT NULL, est_actif TINYINT NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE type_rdv (id INT AUTO_INCREMENT NOT NULL, libelle VARCHAR(50) NOT NULL, couleur_hex VARCHAR(7) NOT NULL, est_actif TINYINT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE utilisateur (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, mot_de_passe VARCHAR(255) NOT NULL, nom VARCHAR(100) NOT NULL, prenom VARCHAR(100) NOT NULL, telephone VARCHAR(20) DEFAULT NULL, roles JSON NOT NULL, est_actif TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_utilisateur_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE creneau ADD CONSTRAINT FK_F9668B5F26894FF9 FOREIGN KEY (id_personnel) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE creneau ADD CONSTRAINT FK_F9668B5F3F0033A2 FOREIGN KEY (id_service) REFERENCES service (id)');
        $this->addSql('ALTER TABLE creneau ADD CONSTRAINT FK_F9668B5F420F15F2 FOREIGN KEY (id_type_rdv) REFERENCES type_rdv (id)');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C8495527FB222F FOREIGN KEY (id_creneau) REFERENCES creneau (id)');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C84955D6AD9786 FOREIGN KEY (id_auditeur) REFERENCES utilisateur (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE creneau DROP FOREIGN KEY FK_F9668B5F26894FF9');
        $this->addSql('ALTER TABLE creneau DROP FOREIGN KEY FK_F9668B5F3F0033A2');
        $this->addSql('ALTER TABLE creneau DROP FOREIGN KEY FK_F9668B5F420F15F2');
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY FK_42C8495527FB222F');
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY FK_42C84955D6AD9786');
        $this->addSql('DROP TABLE creneau');
        $this->addSql('DROP TABLE reservation');
        $this->addSql('DROP TABLE service');
        $this->addSql('DROP TABLE type_rdv');
        $this->addSql('DROP TABLE utilisateur');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
