<?php

namespace App\Tests\Service;

use App\Entity\Reservation;
use App\Entity\Salle;
use App\Entity\Seance;
use App\Enum\ReservationStatus;
use App\Service\ScreeningAvailability;
use PHPUnit\Framework\TestCase;

final class ScreeningAvailabilityTest extends TestCase
{
    public function testOnlyPendingAndPaidReservationsReduceAvailableSeats(): void
    {
        $seance = (new Seance())->setSalle((new Salle())->setCapacity(10));
        $seance
            ->addReservation((new Reservation())->setNbPlaces(2)->setStatus(ReservationStatus::PENDING))
            ->addReservation((new Reservation())->setNbPlaces(3)->setStatus(ReservationStatus::PAID))
            ->addReservation((new Reservation())->setNbPlaces(4)->setStatus(ReservationStatus::CANCELLED));

        self::assertSame([
            'reservedSeats' => 5,
            'capacity' => 10,
            'availableSeats' => 5,
            'occupancyPercent' => 50,
        ], (new ScreeningAvailability())->calculate($seance));
    }

    public function testAvailabilityCannotBecomeNegative(): void
    {
        $seance = (new Seance())->setSalle((new Salle())->setCapacity(2));
        $seance->addReservation(
            (new Reservation())->setNbPlaces(4)->setStatus(ReservationStatus::PAID)
        );

        self::assertSame(0, (new ScreeningAvailability())->calculate($seance)['availableSeats']);
    }
}
