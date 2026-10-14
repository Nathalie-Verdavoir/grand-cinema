<?php

namespace App\Tests\Service;

use App\Entity\Billet;
use App\Entity\Salle;
use App\Entity\Seance;
use App\Entity\Tarif;
use App\Entity\User;
use App\Enum\ReservationChannel;
use App\Enum\ReservationStatus;
use App\Enum\SeanceStatus;
use App\Exception\ReservationCreationException;
use App\Repository\ReservationRepository;
use App\Repository\TarifRepository;
use App\Service\ReservationCreator;
use App\Service\ScreeningAvailability;
use DateTime;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ReservationCreatorTest extends TestCase
{
    public function testItCreatesAPendingReservationWithOneTicketPerSelectedPlace(): void
    {
        $standard = $this->tarif(1, 'Tarif Normal', '8.50');
        $child = $this->tarif(2, 'Tarif -6', '5.00');
        $seance = $this->availableSeance(10);
        $persisted = [];

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $events = [];
        $entityManager
            ->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback) => $callback($entityManager));
        $entityManager
            ->expects(self::once())
            ->method('lock')
            ->with($seance, LockMode::PESSIMISTIC_WRITE)
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'lock';
            });
        $entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            }
        );

        $reservationRepository = $this->createMock(ReservationRepository::class);
        $reservationRepository
            ->expects(self::once())
            ->method('countReservedSeatsForSeance')
            ->with($seance)
            ->willReturnCallback(static function () use (&$events): int {
                $events[] = 'count';

                return 0;
            });
        $tarifRepository = $this->createMock(TarifRepository::class);
        $tarifRepository->method('findAll')->willReturn([$standard, $child]);
        $creator = new ReservationCreator(
            $entityManager,
            $tarifRepository,
            new ScreeningAvailability(),
            $reservationRepository
        );
        $customer = new User();

        $reservation = $creator->create($seance, $customer, ['1' => '2', '2' => '1']);

        self::assertSame(['lock', 'count'], $events);
        self::assertSame(ReservationStatus::PENDING, $reservation->getStatus());
        self::assertSame(ReservationChannel::ONLINE, $reservation->getChannel());
        self::assertSame($customer, $reservation->getUser());
        self::assertSame(3, $reservation->getNbPlaces());
        self::assertSame('22.00', $reservation->getTotalPrice());
        self::assertCount(3, $reservation->getBillets());
        self::assertCount(4, $persisted);
        self::assertSame(
            ['8.50', '8.50', '5.00'],
            array_map(static fn (Billet $billet): ?string => $billet->getPriceApplied(), $reservation->getBillets()->toArray())
        );
    }

    public function testItRejectsASelectionAboveRemainingCapacity(): void
    {
        $tarif = $this->tarif(1, 'Tarif Normal', '8.50');
        $seance = $this->availableSeance(3);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback) => $callback($entityManager));
        $entityManager->expects(self::once())->method('lock')->with($seance, LockMode::PESSIMISTIC_WRITE);
        $entityManager->expects(self::never())->method('persist');

        $reservationRepository = $this->createMock(ReservationRepository::class);
        $reservationRepository
            ->expects(self::once())
            ->method('countReservedSeatsForSeance')
            ->with($seance)
            ->willReturn(2);
        $tarifRepository = $this->createMock(TarifRepository::class);
        $tarifRepository->method('findAll')->willReturn([$tarif]);
        $creator = new ReservationCreator(
            $entityManager,
            $tarifRepository,
            new ScreeningAvailability(),
            $reservationRepository
        );

        $this->expectException(ReservationCreationException::class);
        $this->expectExceptionMessage('Il ne reste pas assez de places');
        $creator->create($seance, new User(), ['1' => '2']);
    }

    public function testItCreatesAPaidAtCounterReservationForTheAdmin(): void
    {
        $tarif = $this->tarif(1, 'Tarif Normal', '8.50');
        $seance = $this->availableSeance(2);
        $admin = new User();
        $persisted = [];

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback) => $callback($entityManager));
        $entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            }
        );
        $reservationRepository = $this->createMock(ReservationRepository::class);
        $reservationRepository->method('countReservedSeatsForSeance')->with($seance)->willReturn(0);
        $tarifRepository = $this->createMock(TarifRepository::class);
        $tarifRepository->method('findAll')->willReturn([$tarif]);

        $reservation = (new ReservationCreator(
            $entityManager,
            $tarifRepository,
            new ScreeningAvailability(),
            $reservationRepository
        ))
            ->createAtCounter($seance, $admin, ['1' => '2']);

        self::assertSame(ReservationStatus::PAID_AT_COUNTER, $reservation->getStatus());
        self::assertSame(ReservationChannel::COUNTER, $reservation->getChannel());
        self::assertSame($admin, $reservation->getUser());
        self::assertSame('17.00', $reservation->getTotalPrice());
        self::assertCount(2, $reservation->getBillets());
        self::assertCount(3, $persisted);
    }

    private function tarif(int $id, string $name, string $price): Tarif
    {
        $tarif = (new Tarif())->setName($name)->setPrice($price);
        (new \ReflectionProperty(Tarif::class, 'id'))->setValue($tarif, $id);

        return $tarif;
    }

    private function availableSeance(int $capacity): Seance
    {
        return (new Seance())
            ->setDateTime(new DateTime('+1 day'))
            ->setStatus(SeanceStatus::PUBLISHED)
            ->setSalle((new Salle())->setCapacity($capacity));
    }
}
