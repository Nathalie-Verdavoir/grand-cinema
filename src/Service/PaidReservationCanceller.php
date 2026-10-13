<?php

namespace App\Service;

use App\Entity\Reservation;
use App\Enum\ReservationStatus;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Exception\ApiErrorException;

final class PaidReservationCanceller
{
    public function __construct(
        private readonly ReservationPaymentSessionManager $paymentSessionManager,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @throws ApiErrorException */
    public function cancel(Reservation $reservation, ?\DateTimeImmutable $now = null): bool
    {
        if (!$reservation->canBeCancelledAfterPayment($now)) {
            return false;
        }

        $this->paymentSessionManager->refundPaidReservation($reservation);

        $reservation->setStatus(ReservationStatus::REFUNDED);
        $this->entityManager->flush();

        return true;
    }
}
