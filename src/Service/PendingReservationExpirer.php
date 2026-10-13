<?php

namespace App\Service;

use App\Enum\ReservationStatus;
use App\Repository\ReservationRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final class PendingReservationExpirer
{
    public function __construct(
        private readonly ReservationRepository $reservationRepository,
        private readonly ReservationPaymentSessionManager $paymentSessionManager,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function expireBefore(DateTimeImmutable $cutoff): int
    {
        $expiredCount = 0;
        foreach ($this->reservationRepository->findPendingBefore($cutoff) as $reservation) {
            if (!$this->paymentSessionManager->expireCheckoutSession($reservation)) {
                continue;
            }

            if (ReservationStatus::PENDING !== $reservation->getStatus()) {
                continue;
            }

            $reservation->setStatus(ReservationStatus::EXPIRED);
            $this->entityManager->flush();
            ++$expiredCount;
        }

        return $expiredCount;
    }
}
