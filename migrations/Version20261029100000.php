<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep what the check said about the car when a refresh woke it';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE refresh_attempt ADD woke_from VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE refresh_attempt DROP woke_from');
    }
}
