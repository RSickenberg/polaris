<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add lease_contract.today_whole_day (compare Today with the whole day instead of a prorated part)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lease_contract ADD today_whole_day BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lease_contract DROP today_whole_day');
    }
}
