<?php

namespace App\Tests\EventSubscriber;

use App\Entity\Reservation;
use App\Enum\ReservationStatus;
use App\EventSubscriber\ReservationKpiAggregateSubscriber;
use App\Service\AdminKpiAggregateUpdaterInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;

final class ReservationKpiAggregateSubscriberTest extends TestCase
{
    public function testItConvertsScalarStatusChangesToReservationEnums(): void
    {
        $reservation = new Reservation();
        $unitOfWork = $this->getMockBuilder(UnitOfWork::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getScheduledEntityUpdates', 'getEntityChangeSet'])
            ->getMock();
        $unitOfWork
            ->expects(self::once())
            ->method('getScheduledEntityUpdates')
            ->willReturn([$reservation]);
        $unitOfWork
            ->expects(self::once())
            ->method('getEntityChangeSet')
            ->with($reservation)
            ->willReturn(['status' => ['pending', 'cancelled']]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('getUnitOfWork')
            ->willReturn($unitOfWork);

        $aggregateUpdater = $this->createMock(AdminKpiAggregateUpdaterInterface::class);
        $aggregateUpdater
            ->expects(self::once())
            ->method('applyStatusChange')
            ->with($reservation, ReservationStatus::PENDING, ReservationStatus::CANCELLED);

        $subscriber = new ReservationKpiAggregateSubscriber($aggregateUpdater);
        $subscriber->onFlush(new OnFlushEventArgs($entityManager));
    }
}
