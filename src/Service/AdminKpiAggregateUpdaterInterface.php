<?php

namespace App\Service;

use App\Entity\Reservation;
use App\Enum\ReservationStatus;

interface AdminKpiAggregateUpdaterInterface
{
    public function applyStatusChange(
        Reservation $reservation,
        ?ReservationStatus $previousStatus,
        ?ReservationStatus $newStatus,
    ): void;
}
