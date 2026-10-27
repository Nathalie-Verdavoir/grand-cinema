<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012130017 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the screening publication status.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE seance ADD status VARCHAR(20) DEFAULT 'draft' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE seance DROP status');
    }
}
