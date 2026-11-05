<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep a daily projection snapshot per lease for projected-versus-actual charts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE projection_snapshot (id UUID NOT NULL, day DATE NOT NULL, computed_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, as_of TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, driven_metres INT NOT NULL, allowed_to_date_metres INT NOT NULL, projected_at_end_metres INT NOT NULL, daily_budget_metres INT NOT NULL, risk_level VARCHAR(8) NOT NULL, lease_contract_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_projection_snapshot_lease_day ON projection_snapshot (lease_contract_id, day)');
        $this->addSql('CREATE INDEX IDX_B0DAC73512036BC7 ON projection_snapshot (lease_contract_id)');
        $this->addSql('ALTER TABLE projection_snapshot ADD CONSTRAINT FK_B0DAC73512036BC7 FOREIGN KEY (lease_contract_id) REFERENCES lease_contract (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE projection_snapshot');
    }
}
