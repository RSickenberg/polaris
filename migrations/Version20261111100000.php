<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add lease_contract.quiet_from and quiet_until (usual hours without driving)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lease_contract ADD quiet_from VARCHAR(5) DEFAULT NULL');
        $this->addSql('ALTER TABLE lease_contract ADD quiet_until VARCHAR(5) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lease_contract DROP quiet_from');
        $this->addSql('ALTER TABLE lease_contract DROP quiet_until');
    }
}
