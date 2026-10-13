<?php

namespace App\Tests\Service;

use App\Entity\Reservation;
use App\Enum\ReservationStatus;
use App\Service\PendingReservationCanceller;
use App\Service\ReservationPaymentSessionManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Stripe\Exception\ApiErrorException;

final class PendingReservationCancellerTest extends TestCase
{
    /** @throws ApiErrorException */
    public function testItCancelsAPendingReservationAfterExpiringItsPaymentSession(): void
    {
        $reservation = (new Reservation())->setStatus(ReservationStatus::PENDING);
        $paymentSessionManager = $this->createMock(ReservationPaymentSessionManager::class);
        $paymentSessionManager
            ->expects(self::once())
            ->method('expireCheckoutSession')
            ->with($reservation)
            ->willReturn(true);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $canceller = new PendingReservationCanceller($paymentSessionManager, $entityManager);

        self::assertTrue($canceller->cancel($reservation));
        self::assertSame(ReservationStatus::CANCELLED, $reservation->getStatus());
    }

    /** @throws ApiErrorException */
    public function testItDoesNotCancelWhenPaymentSessionCannotBeExpired(): void
    {
        $reservation = (new Reservation())->setStatus(ReservationStatus::PENDING);
        $paymentSessionManager = $this->createMock(ReservationPaymentSessionManager::class);
        $paymentSessionManager
            ->expects(self::once())
            ->method('expireCheckoutSession')
            ->with($reservation)
            ->willReturnCallback(static function (Reservation $reservation): bool {
                $reservation->setStatus(ReservationStatus::PAID);

                return false;
            });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $canceller = new PendingReservationCanceller($paymentSessionManager, $entityManager);

        self::assertFalse($canceller->cancel($reservation));
        self::assertSame(ReservationStatus::PAID, $reservation->getStatus());
    }

    /** @throws ApiErrorException */
    public function testItDoesNotContactPaymentProviderForANonPendingReservation(): void
    {
        $reservation = (new Reservation())->setStatus(ReservationStatus::PAID);
        $paymentSessionManager = $this->createMock(ReservationPaymentSessionManager::class);
        $paymentSessionManager->expects(self::never())->method('expireCheckoutSession');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $canceller = new PendingReservationCanceller($paymentSessionManager, $entityManager);

        self::assertFalse($canceller->cancel($reservation));
        self::assertSame(ReservationStatus::PAID, $reservation->getStatus());
    }
}
