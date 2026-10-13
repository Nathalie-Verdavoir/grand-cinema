<?php

namespace App\Service;

use App\Entity\Reservation;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

interface InvoiceSender
{
    /** @throws TransportExceptionInterface When the invoice email cannot be delivered. */
    public function send(Reservation $reservation): void;
}
