<?php

namespace App\Service;

use App\Entity\Reservation;
use Stripe\Exception\ApiErrorException;

interface ReservationPaymentSessionManager
{
    /** @throws ApiErrorException */
    public function expireCheckoutSession(Reservation $reservation): bool;
}
