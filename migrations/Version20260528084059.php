<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260528084059 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'US-4.8 : ajoute 2 préférences notifications email sur utilisateur '
            . '(email_modification_commentaire + email_rappel_j1), types "confort" '
            . 'désactivables. DEFAULT 1 NOT NULL → Auditeurs existants tous activés (F1). '
            . 'Base légale RGPD : art. 6.1.b (exécution du contrat). Canal email seul, '
            . 'l\'in-app reste un audit trail (B2).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE utilisateur ADD email_modification_commentaire TINYINT DEFAULT 1 NOT NULL, ADD email_rappel_j1 TINYINT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE utilisateur DROP email_modification_commentaire, DROP email_rappel_j1');
    }
}
