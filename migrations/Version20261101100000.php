<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let a user mark the leased car as returned to the bank';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lease_contract ADD returned_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lease_contract DROP returned_at');
    }
}
