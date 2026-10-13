<?php

namespace App\Tests\Service;

use App\Entity\Reservation;
use App\Entity\Seance;
use App\Enum\ReservationStatus;
use App\Service\PaidReservationCanceller;
use App\Service\ReservationPaymentSessionManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class PaidReservationCancellerTest extends TestCase
{
    private const SCREENING = '2030-01-01 20:00:00';

    public function testItRefundsAPaidReservationUntilOneHourBeforeTheScreening(): void
    {
        $reservation = $this->reservation(ReservationStatus::PAID);
        $manager = $this->createMock(ReservationPaymentSessionManager::class);
        $manager->expects(self::once())->method('refundPaidReservation')->with($reservation);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $canceller = new PaidReservationCanceller($manager, $entityManager);

        self::assertTrue($canceller->cancel($reservation, new \DateTimeImmutable('2030-01-01 19:00:00')));
        self::assertSame(ReservationStatus::REFUNDED, $reservation->getStatus());
    }

    public function testItRefusesToCancelLessThanOneHourBeforeTheScreening(): void
    {
        $reservation = $this->reservation(ReservationStatus::PAID);
        $manager = $this->createMock(ReservationPaymentSessionManager::class);
        $manager->expects(self::never())->method('refundPaidReservation');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $canceller = new PaidReservationCanceller($manager, $entityManager);

        self::assertFalse($canceller->cancel($reservation, new \DateTimeImmutable('2030-01-01 19:00:01')));
        self::assertSame(ReservationStatus::PAID, $reservation->getStatus());
    }

    public function testItIgnoresUnpaidReservations(): void
    {
        $reservation = $this->reservation(ReservationStatus::PENDING);
        $manager = $this->createMock(ReservationPaymentSessionManager::class);
        $manager->expects(self::never())->method('refundPaidReservation');

        $canceller = new PaidReservationCanceller($manager, $this->createMock(EntityManagerInterface::class));

        self::assertFalse($canceller->cancel($reservation, new \DateTimeImmutable('2029-12-31')));
    }

    private function reservation(ReservationStatus $status): Reservation
    {
        $seance = (new Seance())->setDateTime(new \DateTime(self::SCREENING));

        return (new Reservation())->setStatus($status)->setSeance($seance);
    }
}
