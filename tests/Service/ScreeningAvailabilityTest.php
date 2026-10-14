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
    public function testPendingPaidAndCounterPaidReservationsReduceAvailableSeats(): void
    {
        $seance = (new Seance())->setSalle((new Salle())->setCapacity(10));
        $seance
            ->addReservation((new Reservation())->setNbPlaces(2)->setStatus(ReservationStatus::PENDING))
            ->addReservation((new Reservation())->setNbPlaces(3)->setStatus(ReservationStatus::PAID))
            ->addReservation((new Reservation())->setNbPlaces(1)->setStatus(ReservationStatus::PAID_AT_COUNTER))
            ->addReservation((new Reservation())->setNbPlaces(4)->setStatus(ReservationStatus::CANCELLED));

        self::assertSame([
            'reservedSeats' => 6,
            'capacity' => 10,
            'availableSeats' => 4,
            'occupancyPercent' => 60,
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
