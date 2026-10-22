<?php

namespace App\Controller;

use App\Entity\Reservation;
use App\Entity\User;
use App\Enum\ReservationStatus;
use App\Repository\ReservationRepository;
use App\Service\StripeCheckoutService;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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
    public function show(
        string $reference,
        Request $request,
        ReservationRepository $reservationRepository,
        StripeCheckoutService $stripeCheckoutService,
        LoggerInterface $logger,
    ): Response {
        $useCachedReservation = !$request->query->has('session_id') && !$request->query->has('checkout');
        $reservation = $this->findReservation($reference, $reservationRepository, $useCachedReservation);
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('login', [
                '_target_path' => $this->generateUrl('app_reservation_paiement', ['reference' => $reference]),
            ]);
        }
        $this->assertReservationOwner($reservation, $user);

        $sessionId = $request->query->get('session_id');
        $paymentConfirmed = false;
        $paymentConfirmationPending = 'processing' === $request->query->get('checkout');

        if (is_string($sessionId) && '' !== $sessionId) {
            try {
                $paymentConfirmed = $stripeCheckoutService->confirmReturn($reservation, $sessionId);
            } catch (ApiErrorException $exception) {
                $logger->error('Unable to verify the Stripe Checkout return.', [
                    'reservationReference' => $reference,
                    'exception' => $exception,
                ]);
                $paymentConfirmationPending = true;
            }
        }

        return $this->render('reservation/payment.html.twig', [
            'reservation' => $reservation,
            'canPay' => ReservationStatus::PENDING === $reservation->getStatus(),
            'paymentConfirmed' => $paymentConfirmed,
            'paymentConfirmationPending' => $paymentConfirmationPending,
            'checkoutCancelled' => 'cancelled' === $request->query->get('checkout'),
        ]);
    }

    #[Route(
        '/reservation/{reference}/paiement/checkout',
        name: 'app_reservation_paiement_checkout',
        requirements: ['reference' => '[0-9A-HJKMNP-TV-Z]{26}'],
        methods: ['POST']
    )]
    public function checkout(
        string $reference,
        Request $request,
        ReservationRepository $reservationRepository,
        StripeCheckoutService $stripeCheckoutService,
        LoggerInterface $logger,
    ): Response {
        $reservation = $this->findReservation($reference, $reservationRepository);
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('login', [
                '_target_path' => $this->generateUrl('app_reservation_paiement', ['reference' => $reference]),
            ]);
        }
        if ($reservation->getUser() !== $user) {
            throw $this->createNotFoundException('Cette réservation n’existe pas.');
        }

        $csrfToken = $request->request->get('_token');
        if (!is_string($csrfToken) || !$this->isCsrfTokenValid('reservation-payment-'.$reference, $csrfToken)) {
            throw $this->createAccessDeniedException('Le formulaire de paiement a expiré. Veuillez réessayer.');
        }

        if (ReservationStatus::PENDING !== $reservation->getStatus()) {
            return $this->redirectToRoute('app_reservation_paiement', ['reference' => $reference]);
        }

        try {
            $checkoutUrl = $stripeCheckoutService->createOrReuseSession($reservation);
        } catch (ApiErrorException $exception) {
            $logger->error('Unable to create a Stripe Checkout Session.', [
                'reservationReference' => $reference,
                'exception' => $exception,
            ]);
            $this->addFlash('danger', 'Le paiement Stripe est momentanément indisponible. Veuillez réessayer.');

            return $this->redirectToRoute('app_reservation_paiement', ['reference' => $reference]);
        }

        if (null === $checkoutUrl) {
            if (ReservationStatus::PAID === $reservation->getStatus()) {
                $this->addFlash('success', 'Le paiement de votre réservation est confirmé.');

                return $this->redirectToRoute('app_reservation_paiement', ['reference' => $reference]);
            }

            return $this->redirectToRoute('app_reservation_paiement', [
                'reference' => $reference,
                'checkout' => 'processing',
            ]);
        }

        return $this->redirect($checkoutUrl, Response::HTTP_SEE_OTHER);
    }

    private function findReservation(
        string $reference,
        ReservationRepository $reservationRepository,
        bool $useCache = false,
    ): Reservation
    {
        try {
            $ulid = Ulid::fromString($reference);
        } catch (\InvalidArgumentException $exception) {
            throw $this->createNotFoundException('Cette réservation n’existe pas.', $exception);
        }

        $reservation = $useCache
            ? $reservationRepository->findOneByReferenceCached($ulid)
            : $reservationRepository->findOneBy(['reference' => $ulid]);
        if (!$reservation instanceof Reservation) {
            throw $this->createNotFoundException('Cette réservation n’existe pas.');
        }

        return $reservation;
    }

    private function assertReservationOwner(Reservation $reservation, User $user): void
    {
        if ($reservation->getUser() !== $user) {
            throw $this->createNotFoundException('Cette réservation n’existe pas.');
        }
    }
}
