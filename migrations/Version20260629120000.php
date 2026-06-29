<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * US-12.1 — Traçabilité des modifications de comptes au niveau base de données.
 *
 * Mécanisme COMPLÉMENTAIRE au journal applicatif PHP (table journal_admin, non modifiée ici) :
 * un trigger SQL garantit la traçabilité des modifications du compte même lorsqu'elles
 * sont effectuées directement en SQL, hors application. Démontre la maîtrise du SQL avancé
 * (trigger + procédure stockée) en complément de l'ORM.
 */
final class Version20260629120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'US-12.1 : tracabilite des modifications de comptes (table historique + trigger + procedure stockee)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE historique_utilisateur (
                id INT AUTO_INCREMENT NOT NULL,
                utilisateur_id INT NOT NULL,
                champ_modifie VARCHAR(50) NOT NULL,
                ancienne_valeur VARCHAR(255) DEFAULT NULL,
                nouvelle_valeur VARCHAR(255) DEFAULT NULL,
                date_modification DATETIME NOT NULL,
                INDEX idx_historique_utilisateur (utilisateur_id, date_modification),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_historique_utilisateur
            AFTER UPDATE ON utilisateur
            FOR EACH ROW
            BEGIN
                IF OLD.email <> NEW.email THEN
                    INSERT INTO historique_utilisateur (utilisateur_id, champ_modifie, ancienne_valeur, nouvelle_valeur, date_modification)
                    VALUES (NEW.id, 'email', OLD.email, NEW.email, NOW());
                END IF;
                IF OLD.nom <> NEW.nom THEN
                    INSERT INTO historique_utilisateur (utilisateur_id, champ_modifie, ancienne_valeur, nouvelle_valeur, date_modification)
                    VALUES (NEW.id, 'nom', OLD.nom, NEW.nom, NOW());
                END IF;
                IF OLD.prenom <> NEW.prenom THEN
                    INSERT INTO historique_utilisateur (utilisateur_id, champ_modifie, ancienne_valeur, nouvelle_valeur, date_modification)
                    VALUES (NEW.id, 'prenom', OLD.prenom, NEW.prenom, NOW());
                END IF;
                IF OLD.role <> NEW.role THEN
                    INSERT INTO historique_utilisateur (utilisateur_id, champ_modifie, ancienne_valeur, nouvelle_valeur, date_modification)
                    VALUES (NEW.id, 'role', OLD.role, NEW.role, NOW());
                END IF;
                IF OLD.est_actif <> NEW.est_actif THEN
                    INSERT INTO historique_utilisateur (utilisateur_id, champ_modifie, ancienne_valeur, nouvelle_valeur, date_modification)
                    VALUES (NEW.id, 'est_actif', OLD.est_actif, NEW.est_actif, NOW());
                END IF;
                IF OLD.mot_de_passe_hash <> NEW.mot_de_passe_hash THEN
                    INSERT INTO historique_utilisateur (utilisateur_id, champ_modifie, ancienne_valeur, nouvelle_valeur, date_modification)
                    VALUES (NEW.id, 'mot_de_passe', 'modifie', 'modifie', NOW());
                END IF;
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE PROCEDURE consulter_historique_utilisateur(IN p_utilisateur_id INT)
            BEGIN
                SELECT id, champ_modifie, ancienne_valeur, nouvelle_valeur, date_modification
                FROM historique_utilisateur
                WHERE utilisateur_id = p_utilisateur_id
                ORDER BY date_modification DESC;
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP PROCEDURE IF EXISTS consulter_historique_utilisateur');
        $this->addSql('DROP TRIGGER IF EXISTS trg_historique_utilisateur');
        $this->addSql('DROP TABLE IF EXISTS historique_utilisateur');
    }
}
