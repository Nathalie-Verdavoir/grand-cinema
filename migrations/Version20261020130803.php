<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020130803 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Placeholder for an unavailable historical migration; no operation could be reconstructed.';
    }

    public function up(Schema $schema): void
    {
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The original operation for this historical migration is unavailable.');
    }
}
