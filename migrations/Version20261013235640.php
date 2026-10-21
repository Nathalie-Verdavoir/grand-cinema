<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013235640 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track reservation confirmation email delivery.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation ADD confirmation_email_sent_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation DROP confirmation_email_sent_at');
    }
}
