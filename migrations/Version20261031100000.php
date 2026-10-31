<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Log the risk level of a vehicle, to tell a change from a level already known';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE alert_log (id UUID NOT NULL, sent_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, type VARCHAR(16) NOT NULL, risk_level VARCHAR(8) NOT NULL, channel VARCHAR(32) DEFAULT NULL, vehicle_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_5A731319545317D196E4F388 ON alert_log (vehicle_id, sent_at)');
        $this->addSql('CREATE INDEX IDX_5A731319545317D1 ON alert_log (vehicle_id)');
        $this->addSql('ALTER TABLE alert_log ADD CONSTRAINT FK_5A731319545317D1 FOREIGN KEY (vehicle_id) REFERENCES vehicle (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE alert_log');
    }
}
