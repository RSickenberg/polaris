<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the record of the manual odometer refreshes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE refresh_attempt (id UUID NOT NULL, requested_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, outcome VARCHAR(16) NOT NULL, updated_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, vehicle_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C5D08859545317D1 ON refresh_attempt (vehicle_id)');
        $this->addSql('ALTER TABLE refresh_attempt ADD CONSTRAINT FK_C5D08859545317D1 FOREIGN KEY (vehicle_id) REFERENCES vehicle (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE refresh_attempt');
    }
}
