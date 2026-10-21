<?php

namespace App\Repository;

use App\Entity\Reservation;
use App\Entity\Seance;
use App\Enum\ReservationChannel;
use App\Enum\SeanceStatus;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reservation>
 */
class AdminKpiRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservation::class);
    }

    /**
     * @return list<array{screeningDate: DateTimeImmutable|string, timeBucket: int|string, entries: int|string, revenue: string|null, reservations: int|string, counterReservations: int|string}>
     */
    public function findSalesByScreening(DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    screening_date AS screeningDate,
                    time_bucket AS timeBucket,
                    SUM(ticket_count) AS entries,
                    SUM(revenue) AS revenue,
                    SUM(reservation_count) AS reservations,
                    SUM(CASE WHEN channel = :counterChannel THEN reservation_count ELSE 0 END) AS counterReservations
                FROM kpi_daily_sales
                WHERE screening_date >= :from AND screening_date < :until
                GROUP BY screening_date, time_bucket
                ORDER BY screening_date ASC, time_bucket ASC
                SQL,
            [
                'from' => $from->format('Y-m-d'),
                'until' => $until->format('Y-m-d'),
                'counterChannel' => ReservationChannel::COUNTER->value,
            ],
        );
    }

    /**
     * @return list<array{filmTitle: string, entries: int|string, revenue: string|null}>
     */
    public function findSalesByFilm(DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT film.title AS filmTitle, SUM(sales.ticket_count) AS entries, SUM(sales.revenue) AS revenue
                FROM kpi_daily_sales sales
                INNER JOIN film ON film.id = sales.film_id
                WHERE sales.screening_date >= :from AND sales.screening_date < :until
                GROUP BY film.id, film.title
                ORDER BY entries DESC
                SQL,
            ['from' => $from->format('Y-m-d'), 'until' => $until->format('Y-m-d')],
        );
    }

    /**
     * @return list<array{screeningId: int|string, roomId: int|string, capacity: int|string}>
     */
    public function findPublishedScreeningCapacities(DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('seance.id AS screeningId', 'salle.id AS roomId', 'salle.capacity AS capacity')
            ->from(Seance::class, 'seance')
            ->join('seance.salle', 'salle')
            ->andWhere('seance.dateTime >= :from')
            ->andWhere('seance.dateTime < :until')
            ->andWhere('seance.status = :publishedStatus')
            ->setParameter('from', $from)
            ->setParameter('until', $until)
            ->setParameter('publishedStatus', SeanceStatus::PUBLISHED)
            ->getQuery()
            ->getArrayResult();
    }
}
