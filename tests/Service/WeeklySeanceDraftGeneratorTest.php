<?php

namespace App\Tests\Service;

use App\Entity\Film;
use App\Entity\Salle;
use App\Entity\Seance;
use App\Enum\SeanceStatus;
use App\Service\WeeklySeanceDraftGenerator;
use App\Repository\FilmRepository;
use App\Repository\SalleRepository;
use App\Repository\SeanceRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class WeeklySeanceDraftGeneratorTest extends TestCase
{
    public function testGenerateDayFillsEachRoomWithRandomizedFilmRepetitions(): void
    {
        $rooms = [
            (new Salle())->setName('Salle 1')->setCapacity(100),
            (new Salle())->setName('Salle 2')->setCapacity(100),
        ];
        $films = [
            (new Film())->setTitle('Film A')->setDuration(90),
            (new Film())->setTitle('Film B')->setDuration(90),
        ];
        $seances = $this->generate($films, $rooms, null, new DateTimeImmutable('2027-01-04'));

        self::assertCount(14, $seances['persisted']);
        self::assertSame(14, $seances['result']['created']);
        self::assertSame(0, $seances['result']['skipped']);

        foreach ($rooms as $room) {
            $roomSeances = array_values(array_filter(
                $seances['persisted'],
                static fn (Seance $seance): bool => $seance->getSalle() === $room,
            ));
            self::assertCount(7, $roomSeances);
            $filmTitles = array_map(
                static fn (Seance $seance): string => $seance->getFilm()->getTitle(),
                $roomSeances,
            );
            self::assertSame([], array_diff($filmTitles, ['Film A', 'Film B']));
            self::assertLessThan(count($roomSeances), count(array_unique($filmTitles)));
        }
    }

    public function testGenerateWeekFillsSelectedRoomOnly(): void
    {
        $rooms = [
            (new Salle())->setName('Salle 1')->setCapacity(100),
            (new Salle())->setName('Salle 2')->setCapacity(100),
        ];
        $films = [
            (new Film())->setTitle('Film A')->setDuration(90),
            (new Film())->setTitle('Film B')->setDuration(90),
        ];
        $seances = $this->generate($films, $rooms, $rooms[1], new DateTimeImmutable('2027-01-04'), true);

        self::assertCount(49, $seances['persisted']);
        self::assertSame(49, $seances['result']['created']);
        self::assertSame(0, $seances['result']['skipped']);
        foreach ($seances['persisted'] as $seance) {
            self::assertSame($rooms[1], $seance->getSalle());
        }
    }

    public function testGenerateFixtureRangeFillsPastDatesAndRespectsExistingScreenings(): void
    {
        $room = (new Salle())->setName('Salle 1')->setCapacity(100);
        $film = (new Film())->setTitle('Film A')->setDuration(90);
        $existingScreening = (new Seance())
            ->setFilm($film)
            ->setSalle($room)
            ->setDateTime(new \DateTime('2024-01-01 10:00:00'));

        $seances = $this->generate(
            [$film],
            [$room],
            null,
            new DateTimeImmutable('2024-01-01'),
            existingSeances: [$existingScreening],
            fixtureRange: true,
        );

        self::assertCount(6, $seances['persisted']);
        self::assertSame(6, $seances['result']['created']);
        self::assertSame(0, $seances['result']['skipped']);
        foreach ($seances['persisted'] as $seance) {
            self::assertGreaterThanOrEqual('12:00:00', $seance->getDateTime()->format('H:i:s'));
            self::assertSame('2024-01-01', $seance->getDateTime()->format('Y-m-d'));
            self::assertSame(SeanceStatus::PUBLISHED, $seance->getStatus());
        }
    }

    public function testGenerateFixtureRangeKeepsFutureScreeningsAsDrafts(): void
    {
        $room = (new Salle())->setName('Salle 1')->setCapacity(100);
        $film = (new Film())->setTitle('Film A')->setDuration(90);
        $tomorrow = new DateTimeImmutable('tomorrow');

        $seances = $this->generate(
            [$film],
            [$room],
            null,
            $tomorrow,
            fixtureRange: true,
        );

        self::assertNotEmpty($seances['persisted']);
        foreach ($seances['persisted'] as $seance) {
            self::assertSame(SeanceStatus::DRAFT, $seance->getStatus());
        }
    }

    public function testGenerateFixtureRangePrefersShortFilmsToMaximizeScreeningCount(): void
    {
        $room = (new Salle())->setName('Salle 1')->setCapacity(100);
        $films = [
            (new Film())->setTitle('Long film')->setDuration(90),
            (new Film())->setTitle('Short film')->setDuration(25),
        ];

        $seances = $this->generate(
            $films,
            [$room],
            null,
            new DateTimeImmutable('2024-01-05'),
            fixtureRange: true,
        );

        self::assertCount(20, $seances['persisted']);
        foreach ($seances['persisted'] as $seance) {
            self::assertSame('Short film', $seance->getFilm()->getTitle());
        }
        self::assertSame('2024-01-06 00:15:00', end($seances['persisted'])->getDateTime()->format('Y-m-d H:i:s'));
    }

    /**
     * @param list<Film> $films
     * @param list<Salle> $rooms
     * @param list<Seance> $existingSeances
     *
     * @return array{persisted: list<Seance>, result: array{created: int, skipped: int}}
     */
    private function generate(
        array $films,
        array $rooms,
        ?Salle $selectedRoom,
        DateTimeImmutable $start,
        bool $weekly = false,
        array $existingSeances = [],
        bool $fixtureRange = false,
    ): array {
        $filmRepository = $this->createMock(FilmRepository::class);
        $filmRepository->method('findProgrammableWithDuration')->willReturn($films);

        $roomRepository = $this->createMock(SalleRepository::class);
        $roomRepository->method('findBy')->willReturn($rooms);

        $seanceRepository = $this->createMock(SeanceRepository::class);
        $seanceRepository->method('findForRoomBetween')->willReturn($existingSeances);

        $persisted = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (Seance $seance) use (&$persisted): void {
            $persisted[] = $seance;
        });

        $generator = new WeeklySeanceDraftGenerator($filmRepository, $roomRepository, $seanceRepository, $entityManager);
        $result = match (true) {
            $fixtureRange => $generator->generateFixtureRange($start, $start),
            $weekly => $generator->generate($start, $selectedRoom),
            default => $generator->generateDay($start),
        };

        return ['persisted' => $persisted, 'result' => $result];
    }
}
