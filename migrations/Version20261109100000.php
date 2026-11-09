<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tesla_app.wake_on_last_check';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tesla_app ADD wake_on_last_check BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tesla_app DROP wake_on_last_check');
    }
}
