<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022113400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Optimize pending reservation seat sums by user.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IDX_RESERVATION_USER_STATUS_PLACES ON reservation (user_id, status, nb_places)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_RESERVATION_USER_STATUS_PLACES ON reservation');
    }
}
