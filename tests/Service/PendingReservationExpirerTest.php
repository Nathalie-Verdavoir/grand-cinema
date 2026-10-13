<?php

namespace App\Tests\Service;

use App\Entity\Reservation;
use App\Enum\ReservationStatus;
use App\Repository\ReservationRepository;
use App\Service\PendingReservationExpirer;
use App\Service\ReservationPaymentSessionManager;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class PendingReservationExpirerTest extends TestCase
{
    public function testItExpiresPendingReservationsOnlyWhenStripeAllowsSeatRelease(): void
    {
        $expiredReservation = (new Reservation())->setStatus(ReservationStatus::PENDING);
        $paymentInProgressReservation = (new Reservation())->setStatus(ReservationStatus::PENDING);

        $repository = $this->createMock(ReservationRepository::class);
        $repository
            ->expects(self::once())
            ->method('findPendingBefore')
            ->with(self::isInstanceOf(DateTimeImmutable::class))
            ->willReturn([$expiredReservation, $paymentInProgressReservation]);

        $paymentSessionManager = $this->createMock(ReservationPaymentSessionManager::class);
        $paymentSessionManager
            ->expects(self::exactly(2))
            ->method('expireCheckoutSession')
            ->willReturnOnConsecutiveCalls(true, false);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $expirer = new PendingReservationExpirer($repository, $paymentSessionManager, $entityManager);

        self::assertSame(1, $expirer->expireBefore(new DateTimeImmutable('-15 minutes')));
        self::assertSame(ReservationStatus::EXPIRED, $expiredReservation->getStatus());
        self::assertSame(ReservationStatus::PENDING, $paymentInProgressReservation->getStatus());
    }
}
