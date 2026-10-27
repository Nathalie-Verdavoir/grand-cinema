<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008141123 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the optional image path to films.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE film ADD img VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE film DROP img');
    }
}
