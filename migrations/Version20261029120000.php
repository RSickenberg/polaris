<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the vehicle_data answers by default';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tesla_app ALTER keep_vehicle_data SET DEFAULT true');
        // The previous migration was never released: nobody has chosen yet, so every app gets the new default.
        $this->addSql('UPDATE tesla_app SET keep_vehicle_data = true');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tesla_app ALTER keep_vehicle_data SET DEFAULT false');
    }
}
