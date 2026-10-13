<?php

namespace App\Service;

use App\Entity\Reservation;

interface ReservationPaymentSessionManager
{
    public function expireCheckoutSession(Reservation $reservation): bool;
}
