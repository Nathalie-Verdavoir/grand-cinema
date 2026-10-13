<?php

namespace App\Service;

use App\Entity\Seance;
use App\Enum\ReservationStatus;

final class ScreeningAvailability
{
    /**
     * @return array{reservedSeats: int, capacity: int, availableSeats: int, occupancyPercent: int}
     */
    public function calculate(Seance $seance): array
    {
        $reservedSeats = 0;
        foreach ($seance->getReservations() as $reservation) {
            if (in_array($reservation->getStatus(), [ReservationStatus::PENDING, ReservationStatus::PAID], true)) {
                $reservedSeats += $reservation->getNbPlaces() ?? 0;
            }
        }

        $capacity = max(0, $seance->getSalle()?->getCapacity() ?? 0);

        return [
            'reservedSeats' => $reservedSeats,
            'capacity' => $capacity,
            'availableSeats' => max(0, $capacity - $reservedSeats),
            'occupancyPercent' => $capacity > 0 ? min(100, (int) round(($reservedSeats / $capacity) * 100)) : 0,
        ];
    }
}
