<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the answer of each live vehicle_data call (without location) for later use';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE vehicle_data_snapshot (id UUID NOT NULL, captured_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, received_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, payload JSONB NOT NULL, vehicle_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B1428123545317D1156F3F45 ON vehicle_data_snapshot (vehicle_id, captured_at)');
        $this->addSql('CREATE INDEX IDX_B1428123545317D1 ON vehicle_data_snapshot (vehicle_id)');
        $this->addSql('ALTER TABLE vehicle_data_snapshot ADD CONSTRAINT FK_B1428123545317D1 FOREIGN KEY (vehicle_id) REFERENCES vehicle (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE vehicle_data_snapshot');
    }
}
