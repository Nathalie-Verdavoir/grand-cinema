<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022134500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Optimize published screening queries by status and date.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IDX_SEANCE_STATUS_DATE_TIME ON seance (status, date_time)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_SEANCE_STATUS_DATE_TIME ON seance');
    }
}
