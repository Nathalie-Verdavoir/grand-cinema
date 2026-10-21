<?php

namespace App\Tests\Service;

use App\Entity\Film;
use App\Entity\Reservation;
use App\Entity\Salle;
use App\Entity\Seance;
use App\Enum\ReservationChannel;
use App\Enum\ReservationStatus;
use App\Enum\SeanceStatus;
use App\Service\AdminKpiAggregateUpdater;
use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

final class AdminKpiAggregateUpdaterTest extends TestCase
{
    public function testItAddsPaidReservationTotalsToTheDailyAggregate(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['ticket_count' => 2, 'revenue' => '17.00']);
        $connection
            ->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::stringContains('INSERT INTO kpi_daily_sales'),
                [
                    'date' => '2026-10-21',
                    'filmId' => 42,
                    'channel' => ReservationChannel::ONLINE->value,
                    'timeBucket' => 3,
                    'ticketCount' => 2,
                    'revenue' => '17.00',
                ],
            )
            ->willReturn(1);
        $connection->expects(self::never())->method('delete');

        $updater = new AdminKpiAggregateUpdater($this->doctrine($connection));
        $updater->applyStatusChange(
            $this->reservationWithPersistedId(77),
            ReservationStatus::PENDING,
            ReservationStatus::PAID,
        );
    }

    public function testItSubtractsRefundedReservationTotalsAndRemovesEmptyRows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['ticket_count' => 2, 'revenue' => '17.00']);
        $connection
            ->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::stringContains('UPDATE kpi_daily_sales'),
                [
                    'date' => '2026-10-21',
                    'filmId' => 42,
                    'channel' => ReservationChannel::ONLINE->value,
                    'timeBucket' => 3,
                    'ticketCount' => 2,
                    'revenue' => '17.00',
                ],
            )
            ->willReturn(1);
        $connection
            ->expects(self::once())
            ->method('delete')
            ->with('kpi_daily_sales', [
                'screening_date' => '2026-10-21',
                'film_id' => 42,
                'channel' => ReservationChannel::ONLINE->value,
                'time_bucket' => 3,
                'reservation_count' => 0,
                'ticket_count' => 0,
                'revenue' => '0.00',
            ])
            ->willReturn(1);

        $updater = new AdminKpiAggregateUpdater($this->doctrine($connection));
        $updater->applyStatusChange(
            $this->reservationWithPersistedId(77),
            ReservationStatus::PAID,
            ReservationStatus::REFUNDED,
        );
    }

    public function testItIgnoresTransitionsBetweenIncludedStatuses(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchAssociative');
        $connection->expects(self::never())->method('executeStatement');
        $connection->expects(self::never())->method('delete');

        $updater = new AdminKpiAggregateUpdater($this->doctrine($connection));
        $updater->applyStatusChange(
            $this->reservationWithPersistedId(77),
            ReservationStatus::PAID,
            ReservationStatus::PAID_AT_COUNTER,
        );
    }

    private function doctrine(Connection $connection): ManagerRegistry
    {
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects(self::once())->method('getConnection')->willReturn($connection);

        return $doctrine;
    }

    private function reservationWithPersistedId(int $id): Reservation
    {
        $film = new Film();
        (new \ReflectionProperty(Film::class, 'id'))->setValue($film, 42);
        $seance = (new Seance())
            ->setDateTime(new DateTime('2026-10-21 15:00'))
            ->setStatus(SeanceStatus::PUBLISHED)
            ->setFilm($film)
            ->setSalle((new Salle())->setCapacity(100));
        $reservation = (new Reservation())
            ->setSeance($seance)
            ->setChannel(ReservationChannel::ONLINE);
        (new \ReflectionProperty(Reservation::class, 'id'))->setValue($reservation, $id);

        return $reservation;
    }
}
