<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Daily wake cap of a Tesla app and the instant a refresh woke the car';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tesla_app ADD daily_wake_cap SMALLINT DEFAULT 3 NOT NULL');
        $this->addSql('ALTER TABLE refresh_attempt ADD woke_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE refresh_attempt DROP woke_at');
        $this->addSql('ALTER TABLE tesla_app DROP daily_wake_cap');
    }
}
