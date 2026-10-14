<?php

namespace App\Tests\Service;

use App\Repository\SeanceRepository;
use App\Service\HighOccupancyScreeningFinder;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class HighOccupancyScreeningFinderTest extends TestCase
{
    public function testItReturnsOnlyScreeningsAboveNinetyPercentOccupancy(): void
    {
        $from = new DateTimeImmutable('2026-10-14 12:00:00');
        $to = $from->modify('+2 days');
        $repository = $this->createMock(SeanceRepository::class);
        $repository->expects(self::once())
            ->method('findPublishedOccupanciesBetween')
            ->with($from, $to)
            ->willReturn([
                [
                    'screeningId' => 1,
                    'screeningDateTime' => new DateTimeImmutable('2026-10-14 18:00:00'),
                    'filmTitle' => 'À exactement 90 %',
                    'roomName' => 'Salle 1',
                    'capacity' => 100,
                    'reservedSeats' => 90,
                ],
                [
                    'screeningId' => 2,
                    'screeningDateTime' => new DateTimeImmutable('2026-10-15 18:00:00'),
                    'filmTitle' => 'Au-dessus de 90 %',
                    'roomName' => 'Salle 2',
                    'capacity' => 100,
                    'reservedSeats' => 91,
                ],
                [
                    'screeningId' => 3,
                    'screeningDateTime' => new DateTimeImmutable('2026-10-15 20:00:00'),
                    'filmTitle' => 'Capacité invalide',
                    'roomName' => 'Salle 3',
                    'capacity' => 0,
                    'reservedSeats' => 1,
                ],
            ]);

        $screenings = (new HighOccupancyScreeningFinder($repository))->findBetween($from, $to);

        self::assertCount(1, $screenings);
        self::assertSame(2, $screenings[0]['id']);
        self::assertSame('Au-dessus de 90 %', $screenings[0]['filmTitle']);
        self::assertSame(91.0, $screenings[0]['occupancyPercent']);
    }
}
