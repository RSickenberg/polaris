<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the credentials, the domain and the regions to the Tesla apps';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tesla_app ADD client_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE tesla_app ADD encrypted_client_secret TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE tesla_app ADD domain VARCHAR(253) DEFAULT NULL');
        $this->addSql('ALTER TABLE tesla_app ADD enabled_regions JSON DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tesla_app DROP client_id');
        $this->addSql('ALTER TABLE tesla_app DROP encrypted_client_secret');
        $this->addSql('ALTER TABLE tesla_app DROP domain');
        $this->addSql('ALTER TABLE tesla_app DROP enabled_regions');
    }
}
