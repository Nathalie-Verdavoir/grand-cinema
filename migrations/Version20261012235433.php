<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012235433 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store ticket prices with cents.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billet MODIFY price_applied NUMERIC(7, 2) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billet MODIFY price_applied NUMERIC(5, 0) NOT NULL');
    }
}
