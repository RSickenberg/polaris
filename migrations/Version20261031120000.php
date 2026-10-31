<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let a user opt in to error reports and to vehicle data in them (off for existing users)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD error_reporting BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE app_user ADD error_reporting_details BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP error_reporting');
        $this->addSql('ALTER TABLE app_user DROP error_reporting_details');
    }
}
