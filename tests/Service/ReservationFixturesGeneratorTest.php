<?php

namespace App\Tests\Service;

use App\Entity\Billet;
use App\Entity\Reservation;
use App\Entity\Salle;
use App\Entity\Seance;
use App\Entity\Tarif;
use App\Entity\User;
use App\Enum\ReservationChannel;
use App\Enum\ReservationStatus;
use App\Enum\SeanceStatus;
use App\Repository\SeanceRepository;
use App\Service\ReservationFixturesGenerator;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class ReservationFixturesGeneratorTest extends TestCase
{
    public function testGenerateCreatesRealisticTicketedSalesForRecentAndOlderSessions(): void
    {
        $room = (new Salle())->setName('Salle de test')->setCapacity(100);
        $olderScreening = $this->createScreening($room, '2026-09-01 19:00:00');
        $recentScreening = $this->createScreening($room, '2026-10-15 19:00:00');
        $screeningsByDate = [
            '2026-09-01' => [$olderScreening],
            '2026-10-15' => [$recentScreening],
        ];

        $seanceRepository = $this->createMock(SeanceRepository::class);
        $seanceRepository->method('findPublishedBetween')->willReturnCallback(
            static fn (DateTimeImmutable $from): array => $screeningsByDate[$from->format('Y-m-d')] ?? [],
        );

        $reservations = [];
        $tickets = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use (&$reservations, &$tickets): void {
                if ($entity instanceof Reservation) {
                    $reservations[] = $entity;
                } elseif ($entity instanceof Billet) {
                    $tickets[] = $entity;
                }
            },
        );

        $tariffs = [];
        foreach ([
            ['PLEIN', '12.00'],
            ['REDUIT', '9.50'],
            ['ETUDIANT', '8.50'],
            ['ENFANT', '7.00'],
            ['SENIOR', '8.00'],
            ['CHOMEUR', '7.50'],
        ] as [$code, $price]) {
            $tariffs[$code] = (new Tarif())->setCode($code)->setName($code)->setPrice($price);
        }

        $generator = new ReservationFixturesGenerator($entityManager, $seanceRepository);
        $result = $generator->generate(
            new DateTimeImmutable('2026-10-21'),
            [new User()],
            [new User()],
            $tariffs,
        );

        self::assertSame(count($reservations), $result['reservations']);
        self::assertSame(count($tickets), $result['tickets']);
        self::assertNotEmpty($reservations);
        self::assertNotEmpty($tickets);

        $ticketsByScreening = [];
        foreach ($tickets as $ticket) {
            self::assertInstanceOf(Ulid::class, $ticket->getReference());
            self::assertNotNull($ticket->getSeatNumber());
            $screeningKey = $ticket->getReservation()->getSeance()->getDateTime()->format('Y-m-d');
            $ticketsByScreening[$screeningKey][] = $ticket->getSeatNumber();
        }
        foreach ($ticketsByScreening as $seatNumbers) {
            self::assertCount(count(array_unique($seatNumbers)), $seatNumbers);
            self::assertGreaterThanOrEqual(15, count($seatNumbers));
            self::assertLessThanOrEqual(45, count($seatNumbers));
        }

        foreach ($reservations as $reservation) {
            self::assertCount($reservation->getNbPlaces(), $reservation->getBillets());
            self::assertLessThan(
                $reservation->getSeance()->getDateTime(),
                DateTime::createFromImmutable($reservation->getReservedAt()),
            );
            $screeningDate = $reservation->getSeance()->getDateTime()->format('Y-m-d');
            if ('2026-09-01' === $screeningDate) {
                self::assertSame(ReservationChannel::COUNTER, $reservation->getChannel());
                self::assertSame(ReservationStatus::PAID_AT_COUNTER, $reservation->getStatus());
                self::assertLessThan(new DateTimeImmutable('2026-09-30'), $reservation->getReservedAt());
            } else {
                self::assertGreaterThanOrEqual(new DateTimeImmutable('2026-09-30'), $reservation->getReservedAt());
                self::assertContains($reservation->getChannel(), [ReservationChannel::COUNTER, ReservationChannel::ONLINE]);
                self::assertContains($reservation->getStatus(), [ReservationStatus::PAID_AT_COUNTER, ReservationStatus::PAID]);
            }
        }
    }

    private function createScreening(Salle $room, string $dateTime): Seance
    {
        return (new Seance())
            ->setSalle($room)
            ->setDateTime(new DateTime($dateTime))
            ->setStatus(SeanceStatus::PUBLISHED);
    }
}
