<?php

namespace App\Service;

use App\Entity\Seance;

final class ScreeningAvailability
{
    /**
     * @return array{reservedSeats: int, capacity: int, availableSeats: int, occupancyPercent: int}
     */
    public function calculate(Seance $seance, ?int $reservedSeatsOverride = null, ?int $occupancyPercentOverride = null): array
    {
        if (null === $reservedSeatsOverride) {
            $reservedSeats = 0;
            foreach ($seance->getReservations() as $reservation) {
                if ($reservation->getStatus()?->reservesSeats()) {
                    $reservedSeats += $reservation->getNbPlaces() ?? 0;
                }
            }
        } else {
            $reservedSeats = $reservedSeatsOverride;
        }

        $capacity = max(0, $seance->getSalle()?->getCapacity() ?? 0);

        return [
            'reservedSeats' => $reservedSeats,
            'capacity' => $capacity,
            'availableSeats' => max(0, $capacity - $reservedSeats),
            'occupancyPercent' => $occupancyPercentOverride ?? ($capacity > 0 ? min(100, (int) round(($reservedSeats / $capacity) * 100)) : 0),
        ];
    }
}
