<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the Tesla OAuth tokens and the consent date of the Tesla apps';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tesla_app ADD consent_given_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE TABLE oauth_token (id UUID NOT NULL, encrypted_access_token TEXT NOT NULL, encrypted_refresh_token TEXT NOT NULL, expires_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, scopes TEXT NOT NULL, status VARCHAR(16) NOT NULL, method VARCHAR(32) NOT NULL, fleet_api_base_url VARCHAR(255) NOT NULL, connected_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, refreshed_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, tesla_app_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D8344B2ADBAAB340 ON oauth_token (tesla_app_id)');
        $this->addSql('ALTER TABLE oauth_token ADD CONSTRAINT FK_D8344B2ADBAAB340 FOREIGN KEY (tesla_app_id) REFERENCES tesla_app (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE oauth_token');
        $this->addSql('ALTER TABLE tesla_app DROP consent_given_at');
    }
}
