<?php

namespace App\Service;

use App\Enum\ReservationStatus;
use App\Enum\SeanceStatus;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

final class AdminKpiAggregateRebuilder
{
    private readonly Connection $connection;

    public function __construct(ManagerRegistry $doctrine)
    {
        $this->connection = $doctrine->getConnection();
    }

    /**
     * @param null|callable(DateTimeImmutable, int): void $onDayRebuilt
     *
     * @return array{days: int, rows: int}
     */
    public function rebuildAll(?callable $onDayRebuilt = null): array
    {
        $range = $this->findDateRange();
        if (null === $range) {
            return ['days' => 0, 'rows' => 0];
        }

        $days = 0;
        $rows = 0;
        for ($date = $range['from']; $date <= $range['to']; $date = $date->modify('+1 day')) {
            $dayRows = $this->rebuildDay($date);
            ++$days;
            $rows += $dayRows;
            if (null !== $onDayRebuilt) {
                $onDayRebuilt($date, $dayRows);
            }
        }

        return ['days' => $days, 'rows' => $rows];
    }

    public function rebuildDay(DateTimeImmutable $date): int
    {
        $from = $date->setTime(0, 0);
        $until = $from->modify('+1 day');

        return $this->connection->transactional(function (Connection $connection) use ($from, $until): int {
            $connection->delete('kpi_daily_sales', ['screening_date' => $from->format('Y-m-d')]);

            return $connection->executeStatement(
                <<<'SQL'
                    INSERT INTO kpi_daily_sales (
                        screening_date,
                        film_id,
                        channel,
                        time_bucket,
                        reservation_count,
                        ticket_count,
                        revenue
                    )
                    SELECT
                        DATE(seance.date_time),
                        seance.film_id,
                        reservation.channel,
                        CASE
                            WHEN HOUR(seance.date_time) < 10 THEN 0
                            ELSE 1 + FLOOR((HOUR(seance.date_time) - 10) / 2)
                        END,
                        COUNT(DISTINCT reservation.id),
                        COUNT(billet.id),
                        SUM(billet.price_applied)
                    FROM seance
                    INNER JOIN reservation ON reservation.seance_id = seance.id
                    INNER JOIN billet ON billet.reservation_id = reservation.id
                    WHERE seance.date_time >= :from
                        AND seance.date_time < :until
                        AND seance.status = :publishedStatus
                        AND reservation.status IN (:paidStatus, :counterStatus)
                    GROUP BY
                        DATE(seance.date_time),
                        seance.film_id,
                        reservation.channel,
                        CASE
                            WHEN HOUR(seance.date_time) < 10 THEN 0
                            ELSE 1 + FLOOR((HOUR(seance.date_time) - 10) / 2)
                        END
                    SQL,
                [
                    'from' => $from->format('Y-m-d H:i:s'),
                    'until' => $until->format('Y-m-d H:i:s'),
                    'publishedStatus' => SeanceStatus::PUBLISHED->value,
                    'paidStatus' => ReservationStatus::PAID->value,
                    'counterStatus' => ReservationStatus::PAID_AT_COUNTER->value,
                ],
            );
        });
    }

    /**
     * @return null|array{from: DateTimeImmutable, to: DateTimeImmutable}
     */
    private function findDateRange(): ?array
    {
        $range = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT MIN(day_value) AS first_day, MAX(day_value) AS last_day
                FROM (
                    SELECT DATE(date_time) AS day_value
                    FROM seance
                    WHERE status = :publishedStatus
                    UNION ALL
                    SELECT screening_date AS day_value
                    FROM kpi_daily_sales
                ) AS dates
                SQL,
            ['publishedStatus' => SeanceStatus::PUBLISHED->value],
        );

        if (!is_array($range) || null === $range['first_day'] || null === $range['last_day']) {
            return null;
        }

        return [
            'from' => new DateTimeImmutable($range['first_day']),
            'to' => new DateTimeImmutable($range['last_day']),
        ];
    }
}
