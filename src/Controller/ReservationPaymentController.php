<?php

namespace App\Controller;

use App\Entity\Reservation;
use App\Entity\User;
use App\Repository\ReservationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Ulid;

class ReservationPaymentController extends AbstractController
{
    #[Route(
        '/reservation/{reference}/paiement',
        name: 'app_reservation_paiement',
        requirements: ['reference' => '[0-9A-HJKMNP-TV-Z]{26}'],
        methods: ['GET']
    )]
    public function show(string $reference, ReservationRepository $reservationRepository): Response
    {
        try {
            $ulid = Ulid::fromString($reference);
        } catch (\InvalidArgumentException $exception) {
            throw $this->createNotFoundException('Cette réservation n’existe pas.', $exception);
        }

        $reservation = $reservationRepository->findOneBy(['reference' => $ulid]);
        if (!$reservation instanceof Reservation) {
            throw $this->createNotFoundException('Cette réservation n’existe pas.');
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('login', [
                '_target_path' => $this->generateUrl('app_reservation_paiement', ['reference' => $reference]),
            ]);
        }
        if ($reservation->getUser() !== $user) {
            throw $this->createNotFoundException('Cette réservation n’existe pas.');
        }

        return $this->render('reservation/payment.html.twig', [
            'reservation' => $reservation,
            'canPay' => 'pending' === $reservation->getStatus()?->value,
        ]);
    }
}
