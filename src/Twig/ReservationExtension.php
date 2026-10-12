<?php

namespace App\Twig;

use App\Entity\User;
use App\Repository\ReservationRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ReservationExtension extends AbstractExtension
{
    public function __construct(private readonly ReservationRepository $reservationRepository)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('pending_reservation_seat_count', $this->countPendingSeats(...)),
        ];
    }

    public function countPendingSeats(User $user): int
    {
        return $this->reservationRepository->countPendingSeatsForUser($user);
    }
}
