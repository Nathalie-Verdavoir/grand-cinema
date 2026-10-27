<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add film programmability and duration fields.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE film ADD programmable TINYINT(1) DEFAULT 1 NOT NULL, ADD duration INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE film DROP programmable, DROP duration');
    }
}
