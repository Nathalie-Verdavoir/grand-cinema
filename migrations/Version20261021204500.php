<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021204500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create daily sales aggregates for administrative KPIs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE kpi_daily_sales (
                screening_date DATE NOT NULL,
                film_id INT NOT NULL,
                channel VARCHAR(7) NOT NULL,
                time_bucket TINYINT UNSIGNED NOT NULL,
                reservation_count INT UNSIGNED NOT NULL,
                ticket_count INT UNSIGNED NOT NULL,
                revenue DECIMAL(14, 2) NOT NULL,
                PRIMARY KEY (screening_date, film_id, channel, time_bucket),
                INDEX IDX_KPI_DAILY_FILM_DATE (film_id, screening_date)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE kpi_daily_sales');
    }
}
