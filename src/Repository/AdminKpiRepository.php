<?php

namespace App\Repository;

use App\Entity\Reservation;
use App\Enum\ReservationChannel;
use App\Enum\SeanceStatus;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * @extends ServiceEntityRepository<Reservation>
 */
class AdminKpiRepository extends ServiceEntityRepository
{
    private const RESULT_CACHE_LIFETIME = 300;

    public function __construct(
        ManagerRegistry $registry,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $resultCache,
    )
    {
        parent::__construct($registry, Reservation::class);
    }

    /**
     * @return list<array{screeningDate: DateTimeImmutable|string, timeBucket: int|string, entries: int|string, revenue: string|null, reservations: int|string, counterReservations: int|string}>
     */
    public function findSalesByScreening(DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        return $this->getEntityManager()->getConnection()->executeQuery(
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
            [],
            $this->resultCacheProfile('sales_by_screening', $from, $until),
        )->fetchAllAssociative();
    }

    /**
     * @return list<array{filmTitle: string, entries: int|string, revenue: string|null}>
     */
    public function findSalesByFilm(DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        return $this->getEntityManager()->getConnection()->executeQuery(
            <<<'SQL'
                SELECT film.title AS filmTitle, SUM(sales.ticket_count) AS entries, SUM(sales.revenue) AS revenue
                FROM kpi_daily_sales sales
                INNER JOIN film ON film.id = sales.film_id
                WHERE sales.screening_date >= :from AND sales.screening_date < :until
                GROUP BY film.id, film.title
                ORDER BY entries DESC
                SQL,
            ['from' => $from->format('Y-m-d'), 'until' => $until->format('Y-m-d')],
            [],
            $this->resultCacheProfile('sales_by_film', $from, $until),
        )->fetchAllAssociative();
    }

    /**
     * @return array{capacity: int|string, activeScreens: int|string}
     */
    public function findPublishedCapacityTotals(DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        $row = $this->getEntityManager()->getConnection()->executeQuery(
            <<<'SQL'
                SELECT
                    COALESCE(SUM(salle.capacity * screenings.screening_count), 0) AS capacity,
                    COUNT(*) AS activeScreens
                FROM (
                    SELECT salle_id, COUNT(*) AS screening_count
                    FROM seance
                    WHERE date_time >= :from
                        AND date_time < :until
                        AND status = :publishedStatus
                    GROUP BY salle_id
                ) AS screenings
                INNER JOIN salle ON salle.id = screenings.salle_id
                SQL,
            [
                'from' => $from->format('Y-m-d H:i:s'),
                'until' => $until->format('Y-m-d H:i:s'),
                'publishedStatus' => SeanceStatus::PUBLISHED->value,
            ],
            [],
            $this->resultCacheProfile('published_capacity_totals', $from, $until),
        )->fetchAssociative();

        if (false === $row) {
            throw new \LogicException('La requête des capacités KPI n’a retourné aucun résultat agrégé.');
        }

        return $row;
    }

    private function resultCacheProfile(string $queryName, DateTimeImmutable $from, DateTimeImmutable $until): QueryCacheProfile
    {
        return new QueryCacheProfile(
            self::RESULT_CACHE_LIFETIME,
            $this->resultCacheKey($queryName, $from, $until),
            $this->resultCache,
        );
    }

    private function resultCacheKey(string $queryName, DateTimeImmutable $from, DateTimeImmutable $until): string
    {
        $range = $from->format('Y-m-d H:i:s.uP').'|'.$until->format('Y-m-d H:i:s.uP');

        return 'admin_kpi_'.$queryName.'_'.hash('sha256', $range);
    }
}
