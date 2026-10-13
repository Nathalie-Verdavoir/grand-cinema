<?php

namespace App\Service;

use App\Entity\Reservation;

interface ReservationConfirmationSender
{
    public function send(Reservation $reservation): void;
}
