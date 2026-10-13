<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\ReservationStatus;
use App\Repository\ReservationRepository;
use App\Service\PendingReservationCanceller;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;
use InvalidArgumentException;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class CartController extends AbstractController
{
    #[Route('/mon-panier', name: 'app_cart', methods: ['GET'])]
    public function index(ReservationRepository $reservationRepository): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('cart/index.html.twig', [
            'pendingReservations' => $reservationRepository->findPendingForUser($user),
        ]);
    }

    #[Route(
        '/mon-panier/reservation/{reference}/annuler',
        name: 'app_cart_reservation_cancel',
        requirements: ['reference' => '[0-9A-HJKMNP-TV-Z]{26}'],
        methods: ['POST']
    )]
    public function cancel(
        string $reference,
        Request $request,
        ReservationRepository $reservationRepository,
        PendingReservationCanceller $reservationCanceller,
        LoggerInterface $logger,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        try {
            $ulid = Ulid::fromString($reference);
        } catch (InvalidArgumentException $exception) {
            throw $this->createNotFoundException('Cette réservation n’existe pas.', $exception);
        }

        $reservation = $reservationRepository->findOneForUserByReference($ulid, $user);
        if (null === $reservation) {
            throw $this->createNotFoundException('Cette réservation n’existe pas.');
        }

        $csrfToken = $request->request->get('_token');
        if (!is_string($csrfToken) || !$this->isCsrfTokenValid('cancel-reservation-'.$reference, $csrfToken)) {
            throw $this->createAccessDeniedException('La demande d’annulation a expiré. Veuillez réessayer.');
        }

        try {
            $cancelled = $reservationCanceller->cancel($reservation);
        } catch (ApiErrorException $exception) {
            $logger->error('Unable to cancel the Stripe Checkout Session for a reservation.', [
                'reservationReference' => $reference,
                'exception' => $exception,
            ]);
            $this->addFlash('danger', 'L’annulation est momentanément indisponible. Veuillez réessayer.');

            return $this->redirectToRoute('app_cart');
        }

        if ($cancelled) {
            $this->addFlash('success', 'Votre réservation a bien été annulée.');

            return $this->redirectToRoute('app_cart');
        }

        if (ReservationStatus::PAID === $reservation->getStatus()) {
            $this->addFlash('info', 'Le paiement a été confirmé avant l’annulation. Retrouvez vos billets ici.');

            return $this->redirectToRoute('app_reservation_paiement', ['reference' => $reference]);
        }

        $this->addFlash('warning', 'Cette réservation ne peut plus être annulée pour le moment.');

        return $this->redirectToRoute('app_cart');
    }
}
