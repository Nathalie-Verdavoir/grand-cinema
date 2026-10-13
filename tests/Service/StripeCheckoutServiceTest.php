<?php

namespace App\Tests\Service;

use App\Entity\Reservation;
use App\Enum\ReservationStatus;
use App\Repository\ReservationRepository;
use App\Service\StripeCheckoutService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Stripe\Checkout\Session;
use Stripe\StripeClient;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Ulid;

final class StripeCheckoutServiceTest extends TestCase
{
    public function testItMarksReservationAsPaidForMatchingPaidSession(): void
    {
        $reference = new Ulid();
        $reservation = (new Reservation())
            ->setReference($reference)
            ->setTotalPrice('12.50')
            ->setStatus(ReservationStatus::PENDING)
            ->setStripeCheckoutSessionId('cs_test_expected');

        $reservationRepository = $this->createMock(ReservationRepository::class);
        $reservationRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->willReturn($reservation);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $service = $this->service($reservationRepository, $entityManager);
        $service->handleWebhookSession($this->session($reference, 'cs_test_expected', 'paid'));

        self::assertSame(ReservationStatus::PAID, $reservation->getStatus());
    }

    public function testItIgnoresSessionsThatDoNotMatchTheReservation(): void
    {
        $reference = new Ulid();
        $reservation = (new Reservation())
            ->setReference($reference)
            ->setTotalPrice('12.50')
            ->setStatus(ReservationStatus::PENDING)
            ->setStripeCheckoutSessionId('cs_test_expected');

        $reservationRepository = $this->createMock(ReservationRepository::class);
        $reservationRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->willReturn($reservation);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $service = $this->service($reservationRepository, $entityManager);
        $service->handleWebhookSession($this->session($reference, 'cs_test_other', 'paid'));

        self::assertSame(ReservationStatus::PENDING, $reservation->getStatus());
    }

    public function testItDoesNotMarkAnUnpaidCheckoutSessionAsPaid(): void
    {
        $reference = new Ulid();
        $reservation = (new Reservation())
            ->setReference($reference)
            ->setTotalPrice('12.50')
            ->setStatus(ReservationStatus::PENDING)
            ->setStripeCheckoutSessionId('cs_test_expected');

        $reservationRepository = $this->createMock(ReservationRepository::class);
        $reservationRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->willReturn($reservation);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $service = $this->service($reservationRepository, $entityManager);
        $service->handleWebhookSession($this->session($reference, 'cs_test_expected', 'unpaid'));

        self::assertSame(ReservationStatus::PENDING, $reservation->getStatus());
    }

    public function testItExpiresReservationAfterAsynchronousPaymentFailure(): void
    {
        $reference = new Ulid();
        $reservation = (new Reservation())
            ->setReference($reference)
            ->setTotalPrice('12.50')
            ->setStatus(ReservationStatus::PENDING)
            ->setStripeCheckoutSessionId('cs_test_expected');

        $reservationRepository = $this->createMock(ReservationRepository::class);
        $reservationRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->willReturn($reservation);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $service = $this->service($reservationRepository, $entityManager);
        $service->handleWebhookPaymentFailure($this->session($reference, 'cs_test_expected', 'unpaid'));

        self::assertSame(ReservationStatus::EXPIRED, $reservation->getStatus());
    }

    private function service(
        ReservationRepository $reservationRepository,
        EntityManagerInterface $entityManager,
    ): StripeCheckoutService {
        return new StripeCheckoutService(
            new StripeClient('sk_test_placeholder'),
            $reservationRepository,
            $entityManager,
            $this->createMock(UrlGeneratorInterface::class),
        );
    }

    private function session(Ulid $reference, string $sessionId, string $paymentStatus): Session
    {
        return Session::constructFrom([
            'id' => $sessionId,
            'object' => 'checkout.session',
            'client_reference_id' => (string) $reference,
            'metadata' => ['reservation_reference' => (string) $reference],
            'mode' => 'payment',
            'currency' => 'eur',
            'amount_total' => 1250,
            'payment_status' => $paymentStatus,
            'status' => 'complete',
        ]);
    }
}
