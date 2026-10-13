<?php

namespace App\Service;

use App\Entity\Reservation;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

interface RefundConfirmationSender
{
    /** @throws TransportExceptionInterface When the confirmation email cannot be delivered. */
    public function send(Reservation $reservation): void;
}
