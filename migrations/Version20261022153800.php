<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022153800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cover published screening capacity KPI queries with a composite index.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IDX_SEANCE_STATUS_DATE_ROOM ON seance (status, date_time, salle_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_SEANCE_STATUS_DATE_ROOM ON seance');
    }
}
