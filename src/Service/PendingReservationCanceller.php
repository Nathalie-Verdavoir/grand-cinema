<?php

namespace App\Service;

use App\Entity\Reservation;
use App\Enum\ReservationStatus;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Exception\ApiErrorException;

final class PendingReservationCanceller
{
    public function __construct(
        private readonly ReservationPaymentSessionManager $paymentSessionManager,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @throws ApiErrorException */
    public function cancel(Reservation $reservation): bool
    {
        if (ReservationStatus::PENDING !== $reservation->getStatus()) {
            return false;
        }

        if (!$this->paymentSessionManager->expireCheckoutSession($reservation)) {
            return false;
        }

        $reservation->setStatus(ReservationStatus::CANCELLED);
        $this->entityManager->flush();

        return true;
    }
}
