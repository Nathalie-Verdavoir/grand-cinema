<?php

namespace App\Service;

use App\Entity\Reservation;
use App\Enum\ReservationStatus;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Checkout\Session;
use Stripe\StripeClient;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Ulid;

final class StripeCheckoutService
{
    public function __construct(
        private readonly StripeClient $stripeClient,
        private readonly ReservationRepository $reservationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function createOrReuseSession(Reservation $reservation): ?string
    {
        $reference = $this->reservationReference($reservation);
        $previousSessionId = $reservation->getStripeCheckoutSessionId();

        if (null !== $previousSessionId) {
            $existingSession = $this->stripeClient->checkout->sessions->retrieve($previousSessionId);
            if (!$this->sessionMatchesReservation($existingSession, $reservation)) {
                throw new \LogicException('La session Stripe ne correspond pas à la réservation.');
            }

            if ('open' === $existingSession->status) {
                return $this->sessionUrl($existingSession);
            }

            if ('complete' === $existingSession->status) {
                $this->markPaidIfConfirmed($existingSession, $reservation);

                return null;
            }
        }

        $amountInCents = $this->amountInCents($reservation);
        $sessionParameters = [
            'mode' => 'payment',
            'line_items' => [[
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => [
                        'name' => sprintf('Réservation cinéma %s', $reference),
                    ],
                    'unit_amount' => $amountInCents,
                ],
                'quantity' => 1,
            ]],
            'client_reference_id' => $reference,
            'metadata' => [
                'reservation_reference' => $reference,
            ],
            'success_url' => sprintf(
                '%s?session_id={CHECKOUT_SESSION_ID}',
                $this->urlGenerator->generate(
                    'app_reservation_paiement',
                    ['reference' => $reference],
                    UrlGeneratorInterface::ABSOLUTE_URL
                )
            ),
            'cancel_url' => $this->urlGenerator->generate(
                'app_reservation_paiement',
                ['reference' => $reference, 'checkout' => 'cancelled'],
                UrlGeneratorInterface::ABSOLUTE_URL
            ),
        ];
        $payloadFingerprint = hash('sha256', json_encode($sessionParameters, JSON_THROW_ON_ERROR));
        $attemptFingerprint = null === $previousSessionId
            ? 'initial'
            : substr(hash('sha256', $previousSessionId), 0, 16);

        $session = $this->stripeClient->checkout->sessions->create($sessionParameters, [
            'idempotency_key' => sprintf(
                'reservation-%s-%s-%s',
                $reference,
                $attemptFingerprint,
                $payloadFingerprint
            ),
        ]);

        if (!is_string($session->id) || '' === $session->id) {
            throw new \LogicException('Stripe n’a pas retourné d’identifiant de session.');
        }

        $reservation->setStripeCheckoutSessionId($session->id);
        $this->entityManager->flush();

        return $this->sessionUrl($session);
    }

    public function confirmReturn(Reservation $reservation, string $sessionId): bool
    {
        $storedSessionId = $reservation->getStripeCheckoutSessionId();
        if (null === $storedSessionId || !hash_equals($storedSessionId, $sessionId)) {
            return false;
        }

        $session = $this->stripeClient->checkout->sessions->retrieve($sessionId);
        if (!$this->sessionMatchesReservation($session, $reservation)) {
            return false;
        }

        return $this->markPaidIfConfirmed($session, $reservation);
    }

    public function handleWebhookSession(Session $session): void
    {
        $reference = $session->metadata?->reservation_reference;
        if (!is_string($reference) || '' === $reference) {
            return;
        }

        try {
            $ulid = Ulid::fromString($reference);
        } catch (\InvalidArgumentException) {
            return;
        }

        $reservation = $this->reservationRepository->findOneBy(['reference' => $ulid]);
        if (
            !$reservation instanceof Reservation
            || !$this->sessionMatchesReservation($session, $reservation)
        ) {
            return;
        }

        $this->markPaidIfConfirmed($session, $reservation);
    }

    private function markPaidIfConfirmed(Session $session, Reservation $reservation): bool
    {
        if ('paid' !== $session->payment_status) {
            return false;
        }

        if (ReservationStatus::PAID === $reservation->getStatus()) {
            return true;
        }
        if (ReservationStatus::PENDING !== $reservation->getStatus()) {
            return false;
        }

        $reservation->setStatus(ReservationStatus::PAID);
        $this->entityManager->flush();

        return true;
    }

    private function sessionMatchesReservation(Session $session, Reservation $reservation): bool
    {
        $reference = $this->reservationReference($reservation);

        return $session->id === $reservation->getStripeCheckoutSessionId()
            && $session->client_reference_id === $reference
            && $session->metadata?->reservation_reference === $reference
            && 'payment' === $session->mode
            && 'eur' === $session->currency
            && $session->amount_total === $this->amountInCents($reservation);
    }

    private function reservationReference(Reservation $reservation): string
    {
        $reference = $reservation->getReference();
        if (null === $reference) {
            throw new \LogicException('La réservation doit posséder une référence avant le paiement.');
        }

        return (string) $reference;
    }

    private function amountInCents(Reservation $reservation): int
    {
        $totalPrice = $reservation->getTotalPrice();
        if (!is_string($totalPrice) || !preg_match('/^\d+(?:\.\d{1,2})?$/D', $totalPrice)) {
            throw new \LogicException('Le montant de la réservation est invalide.');
        }

        [$euros, $cents] = array_pad(explode('.', $totalPrice, 2), 2, '');
        $amountInCents = (int) $euros * 100 + (int) str_pad($cents, 2, '0');
        if ($amountInCents <= 0) {
            throw new \LogicException('Le montant de la réservation doit être supérieur à zéro.');
        }

        return $amountInCents;
    }

    private function sessionUrl(Session $session): string
    {
        if (!is_string($session->url) || '' === $session->url) {
            throw new \LogicException('Stripe n’a pas retourné d’URL de paiement.');
        }

        return $session->url;
    }
}
