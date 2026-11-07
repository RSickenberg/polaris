<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Run the daily jobs at the owner\'s midnight: local day, cutoff and pace on the projection snapshot, last midnight run on the user';
    }

    public function up(Schema $schema): void
    {
        // Rows saved before this keep their UTC day label as local_day and have no cutoff and no pace.
        $this->addSql('ALTER TABLE projection_snapshot RENAME COLUMN day TO local_day');
        $this->addSql('ALTER TABLE projection_snapshot ADD cutoff_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE projection_snapshot ADD overall_pace_metres_per_day DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE projection_snapshot ADD recent_pace_metres_per_day DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE projection_snapshot ADD blended_pace_metres_per_day DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD last_midnight_day DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP last_midnight_day');
        $this->addSql('ALTER TABLE projection_snapshot DROP blended_pace_metres_per_day');
        $this->addSql('ALTER TABLE projection_snapshot DROP recent_pace_metres_per_day');
        $this->addSql('ALTER TABLE projection_snapshot DROP overall_pace_metres_per_day');
        $this->addSql('ALTER TABLE projection_snapshot DROP cutoff_at');
        $this->addSql('ALTER TABLE projection_snapshot RENAME COLUMN local_day TO day');
    }
}
