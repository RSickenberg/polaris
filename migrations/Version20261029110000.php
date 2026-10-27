<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let the user allow keeping the vehicle_data answers, off by default';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tesla_app ADD keep_vehicle_data BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tesla_app DROP keep_vehicle_data');
    }
}
