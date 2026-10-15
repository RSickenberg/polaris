<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the Tesla apps, with their key pair';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tesla_app (id UUID NOT NULL, public_key TEXT NOT NULL, encrypted_private_key TEXT NOT NULL, key_generated_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_70E8C5EB7E3C61F9 ON tesla_app (owner_id)');
        $this->addSql('ALTER TABLE tesla_app ADD CONSTRAINT FK_70E8C5EB7E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tesla_app');
    }
}
