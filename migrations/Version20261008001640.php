<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008001640 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the initial cinema, screening, reservation and ticket tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE film (
                id INT AUTO_INCREMENT NOT NULL,
                title VARCHAR(255) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                release_date DATETIME NOT NULL,
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE salle (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(100) NOT NULL,
                capacity INT NOT NULL,
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE tarif (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(100) NOT NULL,
                price NUMERIC(5, 2) NOT NULL,
                code VARCHAR(20) NOT NULL,
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE seance (
                id INT AUTO_INCREMENT NOT NULL,
                film_id INT NOT NULL,
                salle_id INT NOT NULL,
                date_time DATETIME NOT NULL,
                INDEX IDX_DF7DFD0E567F5183 (film_id),
                INDEX IDX_DF7DFD0EDC304035 (salle_id),
                PRIMARY KEY (id),
                CONSTRAINT FK_DF7DFD0E567F5183 FOREIGN KEY (film_id) REFERENCES film (id),
                CONSTRAINT FK_DF7DFD0EDC304035 FOREIGN KEY (salle_id) REFERENCES salle (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE reservation (
                id INT AUTO_INCREMENT NOT NULL,
                user_id BINARY(16) NOT NULL,
                seance_id INT NOT NULL,
                reference BINARY(16) NOT NULL,
                reserved_at DATETIME NOT NULL,
                total_price NUMERIC(7, 2) NOT NULL,
                nb_places INT NOT NULL,
                status VARCHAR(255) NOT NULL,
                INDEX IDX_42C84955A76ED395 (user_id),
                INDEX IDX_42C84955E3797A94 (seance_id),
                PRIMARY KEY (id),
                CONSTRAINT FK_42C84955A76ED395 FOREIGN KEY (user_id) REFERENCES user (id),
                CONSTRAINT FK_42C84955E3797A94 FOREIGN KEY (seance_id) REFERENCES seance (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE billet (
                id INT AUTO_INCREMENT NOT NULL,
                reservation_id INT NOT NULL,
                tarif_id INT NOT NULL,
                price_applied NUMERIC(5, 0) NOT NULL,
                seat_number VARCHAR(10) DEFAULT NULL,
                reference BINARY(16) NOT NULL,
                INDEX IDX_1F034AF6B83297E7 (reservation_id),
                INDEX IDX_1F034AF6357C0A59 (tarif_id),
                PRIMARY KEY (id),
                CONSTRAINT FK_1F034AF6B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservation (id),
                CONSTRAINT FK_1F034AF6357C0A59 FOREIGN KEY (tarif_id) REFERENCES tarif (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE billet');
        $this->addSql('DROP TABLE reservation');
        $this->addSql('DROP TABLE seance');
        $this->addSql('DROP TABLE tarif');
        $this->addSql('DROP TABLE salle');
        $this->addSql('DROP TABLE film');
    }
}
