<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add lease_contract.terms_revision and snapshots_revision (stale projection snapshots after a terms edit)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lease_contract ADD terms_revision INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE lease_contract ADD snapshots_revision INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lease_contract DROP terms_revision');
        $this->addSql('ALTER TABLE lease_contract DROP snapshots_revision');
    }
}
