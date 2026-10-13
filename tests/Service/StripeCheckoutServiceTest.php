<?php

namespace App\Tests\Service;

use App\Entity\Reservation;
use App\Enum\ReservationStatus;
use App\Repository\ReservationRepository;
use App\Service\ReservationConfirmationSender;
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
        $entityManager->expects(self::exactly(2))->method('flush');
        $confirmationSender = $this->createMock(ReservationConfirmationSender::class);
        $confirmationSender->expects(self::once())->method('send')->with($reservation);

        $service = $this->service($reservationRepository, $entityManager, $confirmationSender);
        $service->handleWebhookSession($this->session($reference, 'cs_test_expected', 'paid'));

        self::assertSame(ReservationStatus::PAID, $reservation->getStatus());
        self::assertNotNull($reservation->getConfirmationEmailSentAt());
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

    public function testItRetriesConfirmationEmailAfterSendFailure(): void
    {
        $reference = new Ulid();
        $reservation = (new Reservation())
            ->setReference($reference)
            ->setTotalPrice('12.50')
            ->setStatus(ReservationStatus::PENDING)
            ->setStripeCheckoutSessionId('cs_test_expected');

        $reservationRepository = $this->createMock(ReservationRepository::class);
        $reservationRepository
            ->expects(self::exactly(2))
            ->method('findOneBy')
            ->willReturn($reservation);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('flush');

        $sendAttempt = 0;
        $confirmationSender = $this->createMock(ReservationConfirmationSender::class);
        $confirmationSender
            ->expects(self::exactly(2))
            ->method('send')
            ->with($reservation)
            ->willReturnCallback(static function () use (&$sendAttempt): void {
                if (0 === $sendAttempt++) {
                    throw new \RuntimeException('Mail transport unavailable.');
                }
            });

        $service = $this->service($reservationRepository, $entityManager, $confirmationSender);
        try {
            $service->handleWebhookSession($this->session($reference, 'cs_test_expected', 'paid'));
            self::fail('The failed mail send should be propagated for a webhook retry.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Mail transport unavailable.', $exception->getMessage());
        }

        self::assertSame(ReservationStatus::PAID, $reservation->getStatus());
        self::assertNull($reservation->getConfirmationEmailSentAt());

        $service->handleWebhookSession($this->session($reference, 'cs_test_expected', 'paid'));

        self::assertNotNull($reservation->getConfirmationEmailSentAt());
    }

    public function testItDoesNotResendAConfirmationEmailAlreadySent(): void
    {
        $reference = new Ulid();
        $reservation = (new Reservation())
            ->setReference($reference)
            ->setTotalPrice('12.50')
            ->setStatus(ReservationStatus::PAID)
            ->setStripeCheckoutSessionId('cs_test_expected')
            ->setConfirmationEmailSentAt(new \DateTimeImmutable());

        $reservationRepository = $this->createMock(ReservationRepository::class);
        $reservationRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->willReturn($reservation);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $service = $this->service($reservationRepository, $entityManager);
        $service->handleWebhookSession($this->session($reference, 'cs_test_expected', 'paid'));

        self::assertNotNull($reservation->getConfirmationEmailSentAt());
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
        ?ReservationConfirmationSender $confirmationSender = null,
    ): StripeCheckoutService {
        if (null === $confirmationSender) {
            $confirmationSender = $this->createMock(ReservationConfirmationSender::class);
            $confirmationSender->expects(self::never())->method('send');
        }

        return new StripeCheckoutService(
            new StripeClient('sk_test_placeholder'),
            $reservationRepository,
            $entityManager,
            $this->createMock(UrlGeneratorInterface::class),
            $confirmationSender,
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
