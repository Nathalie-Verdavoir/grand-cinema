<?php

namespace App\Controller;

use App\Entity\Reservation;
use App\Entity\User;
use App\Enum\ReservationStatus;
use App\Repository\ReservationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class ReservationHistoryController extends AbstractController
{
    #[Route('/mes-reservations', name: 'app_reservation_history', methods: ['GET'])]
    public function index(ReservationRepository $reservationRepository): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('reservation/history.html.twig', [
            'reservations' => $reservationRepository->findForUser($user),
        ]);
    }

    #[Route(
        '/mes-reservations/{reference}/billets',
        name: 'app_reservation_tickets',
        requirements: ['reference' => '[0-9A-HJKMNP-TV-Z]{26}'],
        methods: ['GET']
    )]
    public function tickets(string $reference, ReservationRepository $reservationRepository): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $reservation = $reservationRepository->findOneForUserByReference(Ulid::fromString($reference), $user);
        if (!$reservation instanceof Reservation || ReservationStatus::PAID !== $reservation->getStatus()) {
            throw $this->createNotFoundException('Ces billets ne sont pas disponibles.');
        }

        return $this->render('reservation/tickets.html.twig', [
            'reservation' => $reservation,
        ]);
    }
}
