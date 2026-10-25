<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the fetch schedule of a vehicle and the record of its scheduled slots';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE fetch_schedule (id UUID NOT NULL, first_slot VARCHAR(5) NOT NULL, second_slot VARCHAR(5) DEFAULT NULL, vehicle_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8C39A16545317D1 ON fetch_schedule (vehicle_id)');
        $this->addSql('CREATE TABLE fetch_slot_run (id UUID NOT NULL, slot_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, status VARCHAR(16) NOT NULL, attempts INT NOT NULL, updated_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, vehicle_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_90B97DC0545317D1 ON fetch_slot_run (vehicle_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_90B97DC0545317D18C8B8BF0 ON fetch_slot_run (vehicle_id, slot_at)');
        $this->addSql('ALTER TABLE fetch_schedule ADD CONSTRAINT FK_8C39A16545317D1 FOREIGN KEY (vehicle_id) REFERENCES vehicle (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE fetch_slot_run ADD CONSTRAINT FK_90B97DC0545317D1 FOREIGN KEY (vehicle_id) REFERENCES vehicle (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE fetch_slot_run');
        $this->addSql('DROP TABLE fetch_schedule');
    }
}
