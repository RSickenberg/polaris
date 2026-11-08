<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename odometer_reading.confirmed_at to last_verified_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE odometer_reading RENAME COLUMN confirmed_at TO last_verified_at');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE odometer_reading RENAME COLUMN last_verified_at TO confirmed_at');
    }
}
