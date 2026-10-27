<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008152344 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow films without a release date.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE film MODIFY release_date DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE film MODIFY release_date DATETIME NOT NULL');
    }
}
