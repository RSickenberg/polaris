<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the user and link each vehicle to its owner';
    }

    public function up(Schema $schema): void
    {
        // "user" is reserved in PostgreSQL, hence app_user.
        $this->addSql('CREATE TABLE app_user (id UUID NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, locale VARCHAR(8) NOT NULL, time_zone VARCHAR(64) NOT NULL, created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_88BDF3E9E7927C74 ON app_user (email)');
        $this->addSql('ALTER TABLE vehicle ADD owner_id UUID NOT NULL');
        $this->addSql('ALTER TABLE vehicle ADD CONSTRAINT FK_1B80E4867E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_1B80E4867E3C61F9 ON vehicle (owner_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vehicle DROP CONSTRAINT FK_1B80E4867E3C61F9');
        $this->addSql('DROP INDEX IDX_1B80E4867E3C61F9');
        $this->addSql('ALTER TABLE vehicle DROP owner_id');
        $this->addSql('DROP TABLE app_user');
    }
}
