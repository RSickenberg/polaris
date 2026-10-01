<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001110728 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the odometer_reading table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE odometer_reading (id UUID NOT NULL, read_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, odometer_raw NUMERIC(12, 3) DEFAULT NULL, odometer_raw_unit VARCHAR(2) DEFAULT NULL, odometer_m INT NOT NULL, source VARCHAR(16) NOT NULL, vehicle_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C725DCCC545317D1E2DA3872 ON odometer_reading (vehicle_id, read_at)');
        $this->addSql('CREATE INDEX IDX_C725DCCC545317D1 ON odometer_reading (vehicle_id)');
        $this->addSql('ALTER TABLE odometer_reading ADD CONSTRAINT FK_C725DCCC545317D1 FOREIGN KEY (vehicle_id) REFERENCES vehicle (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE odometer_reading DROP CONSTRAINT FK_C725DCCC545317D1');
        $this->addSql('DROP TABLE odometer_reading');
    }
}
