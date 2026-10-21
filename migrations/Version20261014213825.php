<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014213825 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track whether reservations were sold online or at the counter.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE reservation ADD channel VARCHAR(7) DEFAULT 'online' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation DROP channel');
    }
}
