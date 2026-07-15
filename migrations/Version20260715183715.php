<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * US-12.4 — Vérification d'email à l'inscription : ajoute la colonne `email_verifie`
 * à la table `utilisateur`.
 *
 * `DEFAULT 1` : les comptes existants, les fixtures et les comptes créés par le
 * super-admin sont considérés vérifiés d'office. Seule l'auto-inscription auditeur
 * crée un compte à `email_verifie = 0`, débloqué par le lien de confirmation.
 *
 * NB : la table `historique_utilisateur` (trigger US-12.1) n'est volontairement pas
 * mappée par l'ORM ; le `DROP TABLE` que Doctrine proposerait a été retiré à dessein.
 */
final class Version20260715183715 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'US-12.4 : ajoute la colonne email_verifie sur utilisateur (confirmation email a l inscription)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE utilisateur ADD email_verifie TINYINT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE utilisateur DROP email_verifie');
    }
}
