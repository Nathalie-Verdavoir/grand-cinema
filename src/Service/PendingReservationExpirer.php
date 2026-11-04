<?php

namespace App\Service;

use App\Entity\Reservation;
use App\Enum\ReservationStatus;
use App\Repository\ReservationRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Exception\ApiErrorException;

final class PendingReservationExpirer
{
    public function __construct(
        private readonly ReservationRepository $reservationRepository,
        private readonly ReservationPaymentSessionManager $paymentSessionManager,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Filet de sécurité : à appeler avant d'afficher des places ou des réservations,
     * pour ne pas attendre le passage de la commande planifiée.
     */
    public function expireStale(): int
    {
        try {
            return $this->expireBefore(
                (new DateTimeImmutable())->modify(sprintf('-%d minutes', Reservation::PENDING_TIMEOUT_MINUTES))
            );
        } catch (ApiErrorException) {
            // Stripe indisponible : la page reste affichable, la prochaine exécution réessaiera.
            return 0;
        }
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
