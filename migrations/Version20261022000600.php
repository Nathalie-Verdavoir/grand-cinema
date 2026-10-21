<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022000600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index counter reservations by channel and reservation date.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IDX_RESERVATION_CHANNEL_DATE_ID ON reservation (channel, reserved_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_RESERVATION_CHANNEL_DATE_ID ON reservation');
    }
}
