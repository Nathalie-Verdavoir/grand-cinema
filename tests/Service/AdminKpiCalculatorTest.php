<?php

namespace App\Tests\Service;

use App\Repository\AdminKpiRepository;
use App\Service\AdminKpiCalculator;
use App\Service\DateFormatter;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AdminKpiCalculatorTest extends TestCase
{
    public function testItCalculatesOccupancyRevenueAndSalesChannels(): void
    {
        $repository = $this->createMock(AdminKpiRepository::class);
        $repository->method('findSalesByScreening')->willReturn([
            [
                'screeningDate' => new DateTimeImmutable('2026-10-14'),
                'timeBucket' => 3,
                'entries' => 6,
                'revenue' => '50.00',
                'reservations' => 4,
                'counterReservations' => 0,
            ],
            [
                'screeningDate' => new DateTimeImmutable('2026-10-14'),
                'timeBucket' => 3,
                'entries' => 4,
                'revenue' => '35.00',
                'reservations' => 2,
                'counterReservations' => 2,
            ],
        ]);
        $repository->method('findSalesByFilm')->willReturn([
            ['filmTitle' => 'Film test', 'entries' => 10, 'revenue' => '85.00'],
        ]);
        $repository->method('findPublishedScreeningCapacities')->willReturn([
            ['screeningId' => 1, 'roomId' => 2, 'capacity' => 10],
            ['screeningId' => 2, 'roomId' => 2, 'capacity' => 10],
            ['screeningId' => 3, 'roomId' => 3, 'capacity' => 10],
        ]);

        $result = (new AdminKpiCalculator($repository, new DateFormatter()))->calculate(
            new DateTimeImmutable('2026-10-01'),
            new DateTimeImmutable('2026-11-01')
        );

        self::assertSame(10, $result['entries']);
        self::assertSame(85.0, $result['revenue']);
        self::assertSame(8.5, $result['averageRevenuePerEntry']);
        self::assertEqualsWithDelta(33.333, $result['occupancyPercent'], 0.001);
        self::assertEqualsWithDelta(33.333, $result['occupancyChartPercent'], 0.001);
        self::assertSame(2, $result['activeScreens']);
        self::assertSame(42.5, $result['revenuePerScreen']);
        self::assertSame(4, $result['onlineReservations']);
        self::assertSame(2, $result['counterReservations']);
        self::assertEqualsWithDelta(66.667, $result['onlinePercent'], 0.001);
        self::assertEqualsWithDelta(33.333, $result['counterPercent'], 0.001);
        self::assertSame('Mercredi', $result['salesByWeekday'][2]['label']);
        self::assertSame(10, $result['salesByWeekday'][2]['entries']);
        self::assertEqualsWithDelta(100, $result['salesByWeekday'][2]['barPercent'], 0.001);
        self::assertSame('14 h – 16 h', $result['salesByTimeOfDay'][2]['label']);
        self::assertSame(10, $result['salesByTimeOfDay'][2]['entries']);
    }

    public function testItGroupsScreeningsInTwoHourSlotsStartingAtTen(): void
    {
        $repository = $this->createMock(AdminKpiRepository::class);
        $repository->method('findSalesByScreening')->willReturn([
            ['screeningDate' => '2026-10-14', 'timeBucket' => 0, 'entries' => 2, 'revenue' => '0.00', 'reservations' => 1, 'counterReservations' => 0],
            ['screeningDate' => '2026-10-14', 'timeBucket' => 1, 'entries' => 3, 'revenue' => '0.00', 'reservations' => 1, 'counterReservations' => 0],
            ['screeningDate' => '2026-10-14', 'timeBucket' => 1, 'entries' => 4, 'revenue' => '0.00', 'reservations' => 1, 'counterReservations' => 0],
            ['screeningDate' => '2026-10-14', 'timeBucket' => 2, 'entries' => 5, 'revenue' => '0.00', 'reservations' => 1, 'counterReservations' => 0],
            ['screeningDate' => '2026-10-14', 'timeBucket' => 7, 'entries' => 6, 'revenue' => '0.00', 'reservations' => 1, 'counterReservations' => 0],
            ['screeningDate' => '2026-10-14', 'timeBucket' => 7, 'entries' => 7, 'revenue' => '0.00', 'reservations' => 1, 'counterReservations' => 0],
        ]);
        $repository->method('findSalesByFilm')->willReturn([]);
        $repository->method('findPublishedScreeningCapacities')->willReturn([]);

        $result = (new AdminKpiCalculator($repository, new DateFormatter()))->calculate(
            new DateTimeImmutable('2026-10-01'),
            new DateTimeImmutable('2026-11-01')
        );
        $entriesBySlot = array_column($result['salesByTimeOfDay'], 'entries', 'label');

        self::assertSame(2, $entriesBySlot['Avant 10 h']);
        self::assertSame(7, $entriesBySlot['10 h – 12 h']);
        self::assertSame(5, $entriesBySlot['12 h – 14 h']);
        self::assertSame(13, $entriesBySlot['22 h – 00 h']);
    }
}
