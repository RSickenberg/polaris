<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Note on a reading the latest instant the car was seen with the same odometer';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE odometer_reading ADD confirmed_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE odometer_reading DROP confirmed_at');
    }
}
