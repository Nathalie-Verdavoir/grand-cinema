<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013224241 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the password reset request table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE reset_password_request (
                id BINARY(16) NOT NULL,
                user_id BINARY(16) NOT NULL,
                selector VARCHAR(20) NOT NULL,
                hashed_token VARCHAR(100) NOT NULL,
                requested_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL,
                INDEX IDX_7CE748AA76ED395 (user_id),
                PRIMARY KEY (id),
                CONSTRAINT FK_7CE748AA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE reset_password_request');
    }
}
