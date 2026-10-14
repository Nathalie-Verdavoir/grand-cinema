<?php

namespace App\Controller;

use App\Entity\Reservation;
use App\Entity\User;
use App\Enum\ReservationStatus;
use App\Repository\ReservationRepository;
use App\Service\InvoiceSender;
use App\Service\PaidReservationCanceller;
use App\Service\RefundConfirmationSender;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
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
        '/mes-reservations/{reference}/facture',
        name: 'app_reservation_send_invoice',
        requirements: ['reference' => '[0-9A-HJKMNP-TV-Z]{26}'],
        methods: ['POST']
    )]
    public function sendInvoice(
        string $reference,
        Request $request,
        ReservationRepository $reservationRepository,
        InvoiceSender $invoiceSender,
        LoggerInterface $logger,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $reservation = $reservationRepository->findOneForUserByReference(Ulid::fromString($reference), $user);
        if (!$reservation instanceof Reservation || ReservationStatus::PAID !== $reservation->getStatus()) {
            throw $this->createNotFoundException('Cette facture n’est pas disponible.');
        }

        $csrfToken = $request->request->get('_token');
        if (!is_string($csrfToken) || !$this->isCsrfTokenValid('send-invoice-'.$reference, $csrfToken)) {
            throw $this->createAccessDeniedException('La demande a expiré. Veuillez réessayer.');
        }

        try {
            $invoiceSender->send($reservation);
        } catch (TransportExceptionInterface $exception) {
            $logger->error('Unable to send the invoice for a reservation.', [
                'reservationReference' => $reference,
                'exception' => $exception,
            ]);
            $this->addFlash('danger', 'L’envoi de la facture est momentanément indisponible. Veuillez réessayer.');

            return $this->redirectToRoute('app_reservation_history');
        }

        $this->addFlash('success', 'La facture a été envoyée à votre adresse e-mail.');

        return $this->redirectToRoute('app_reservation_history');
    }

    #[Route(
        '/mes-reservations/{reference}/annuler',
        name: 'app_reservation_cancel_paid',
        requirements: ['reference' => '[0-9A-HJKMNP-TV-Z]{26}'],
        methods: ['POST']
    )]
    public function cancelPaid(
        string $reference,
        Request $request,
        ReservationRepository $reservationRepository,
        PaidReservationCanceller $canceller,
        RefundConfirmationSender $refundConfirmationSender,
        LoggerInterface $logger,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $reservation = $reservationRepository->findOneForUserByReference(Ulid::fromString($reference), $user);
        if (!$reservation instanceof Reservation) {
            throw $this->createNotFoundException('Cette réservation n’existe pas.');
        }

        $csrfToken = $request->request->get('_token');
        if (!is_string($csrfToken) || !$this->isCsrfTokenValid('cancel-paid-reservation-'.$reference, $csrfToken)) {
            throw $this->createAccessDeniedException('La demande d’annulation a expiré. Veuillez réessayer.');
        }

        try {
            $cancelled = $canceller->cancel($reservation);
        } catch (ApiErrorException $exception) {
            $logger->error('Unable to refund the Stripe payment for a reservation.', [
                'reservationReference' => $reference,
                'exception' => $exception,
            ]);
            $this->addFlash('danger', 'L’annulation est momentanément indisponible. Veuillez réessayer.');

            return $this->redirectToRoute('app_reservation_history');
        }

        if ($cancelled) {
            $this->addFlash('success', 'Votre réservation a été annulée et le remboursement a été demandé.');
            try {
                $refundConfirmationSender->send($reservation);
            } catch (TransportExceptionInterface $exception) {
                $logger->error('Unable to send the refund confirmation for a reservation.', [
                    'reservationReference' => $reference,
                    'exception' => $exception,
                ]);
                $this->addFlash('danger', 'Le remboursement a été demandé, mais le courriel de confirmation n’a pas pu être envoyé.');
            }
        } else {
            $this->addFlash('warning', 'Cette réservation ne peut plus être annulée : l’annulation est possible jusqu’à une heure avant la séance.');
        }

        return $this->redirectToRoute('app_reservation_history');
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
        if (
            !$reservation instanceof Reservation
            || ReservationStatus::PAID !== $reservation->getStatus()
        ) {
            throw $this->createNotFoundException('Ces billets ne sont pas disponibles.');
        }

        return $this->render('reservation/tickets.html.twig', [
            'reservation' => $reservation,
        ]);
    }
}
