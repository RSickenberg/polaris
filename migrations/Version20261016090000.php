<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the password reset requests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE password_reset_request (id UUID NOT NULL, selector VARCHAR(20) NOT NULL, hashed_verifier VARCHAR(64) NOT NULL, requested_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C5D0A95A9692E25D ON password_reset_request (selector)');
        $this->addSql('CREATE INDEX IDX_C5D0A95AA76ED395 ON password_reset_request (user_id)');
        $this->addSql('ALTER TABLE password_reset_request ADD CONSTRAINT FK_C5D0A95AA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE password_reset_request');
    }
}
