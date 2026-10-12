<?php

namespace App\Tests\Service;

use App\Entity\Billet;
use App\Entity\Reservation;
use App\Entity\Salle;
use App\Entity\Seance;
use App\Entity\Tarif;
use App\Entity\User;
use App\Enum\ReservationStatus;
use App\Enum\SeanceStatus;
use App\Exception\ReservationCreationException;
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
        $entityManager
            ->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback) => $callback($entityManager));
        $entityManager->expects(self::once())->method('lock')->with($seance, LockMode::PESSIMISTIC_WRITE);
        $entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            }
        );

        $tarifRepository = $this->createMock(TarifRepository::class);
        $tarifRepository->method('findAll')->willReturn([$standard, $child]);
        $creator = new ReservationCreator($entityManager, $tarifRepository, new ScreeningAvailability());

        $reservation = $creator->create($seance, new User(), ['1' => '2', '2' => '1']);

        self::assertSame(ReservationStatus::PENDING, $reservation->getStatus());
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
        $seance = $this->availableSeance(1);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback) => $callback($entityManager));
        $entityManager->expects(self::once())->method('lock')->with($seance, LockMode::PESSIMISTIC_WRITE);
        $entityManager->expects(self::never())->method('persist');

        $tarifRepository = $this->createMock(TarifRepository::class);
        $tarifRepository->method('findAll')->willReturn([$tarif]);
        $creator = new ReservationCreator($entityManager, $tarifRepository, new ScreeningAvailability());

        $this->expectException(ReservationCreationException::class);
        $this->expectExceptionMessage('Il ne reste pas assez de places');
        $creator->create($seance, new User(), ['1' => '2']);
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
