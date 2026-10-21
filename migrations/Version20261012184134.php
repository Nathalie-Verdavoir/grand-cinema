<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012184134 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add indexes for screening date and room/date lookups.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IDX_SEANCE_SALLE_DATE_TIME ON seance (salle_id, date_time)');
        $this->addSql('CREATE INDEX IDX_SEANCE_DATE_TIME ON seance (date_time)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_SEANCE_SALLE_DATE_TIME ON seance');
        $this->addSql('DROP INDEX IDX_SEANCE_DATE_TIME ON seance');
    }
}
