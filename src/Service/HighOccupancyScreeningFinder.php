<?php

namespace App\Service;

use App\Repository\SeanceRepository;
use DateTimeImmutable;
use DateTimeInterface;

final class HighOccupancyScreeningFinder
{
    private const OCCUPANCY_THRESHOLD_PERCENT = 90;

    public function __construct(private readonly SeanceRepository $seanceRepository)
    {
    }

    /**
     * @return list<array{id: int, dateTime: DateTimeImmutable, filmTitle: string, roomName: string, reservedSeats: int, capacity: int, occupancyPercent: float}>
     */
    public function findBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $screenings = [];

        foreach ($this->seanceRepository->findPublishedOccupanciesBetween($from, $to) as $row) {
            $capacity = (int) $row['capacity'];
            $reservedSeats = (int) $row['reservedSeats'];

            if ($capacity <= 0 || $reservedSeats * 100 <= $capacity * self::OCCUPANCY_THRESHOLD_PERCENT) {
                continue;
            }

            $dateTime = $row['screeningDateTime'];
            $screenings[] = [
                'id' => (int) $row['screeningId'],
                'dateTime' => $dateTime instanceof DateTimeInterface
                    ? DateTimeImmutable::createFromInterface($dateTime)
                    : new DateTimeImmutable($dateTime),
                'filmTitle' => $row['filmTitle'],
                'roomName' => $row['roomName'],
                'reservedSeats' => $reservedSeats,
                'capacity' => $capacity,
                'occupancyPercent' => ($reservedSeats / $capacity) * 100,
            ];
        }

        return $screenings;
    }
}
