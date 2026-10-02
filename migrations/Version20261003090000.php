<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow one lease contract per vehicle';
    }

    public function up(Schema $schema): void
    {
        // Fails, without changing any data, if a vehicle already has two contracts: merge them first.
        $this->addSql('CREATE UNIQUE INDEX uniq_lease_contract_vehicle ON lease_contract (vehicle_id)');
        // The unique index also serves the vehicle lookups.
        $this->addSql('DROP INDEX IDX_A6F0ACB8545317D1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IDX_A6F0ACB8545317D1 ON lease_contract (vehicle_id)');
        $this->addSql('DROP INDEX uniq_lease_contract_vehicle');
    }
}
