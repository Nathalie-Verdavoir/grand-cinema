<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013005231 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store Stripe checkout session identifiers on reservations.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation ADD stripe_checkout_session_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_RESERVATION_STRIPE_SESSION ON reservation (stripe_checkout_session_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_RESERVATION_STRIPE_SESSION ON reservation');
        $this->addSql('ALTER TABLE reservation DROP stripe_checkout_session_id');
    }
}
