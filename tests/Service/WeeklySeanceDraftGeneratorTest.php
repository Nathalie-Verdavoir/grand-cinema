<?php

namespace App\Tests\Service;

use App\Entity\Film;
use App\Entity\Salle;
use App\Entity\Seance;
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
            self::assertCount(2, array_unique(array_map(
                static fn (Seance $seance): string => $seance->getFilm()->getTitle(),
                $roomSeances,
            )));
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

    /**
     * @param list<Film> $films
     * @param list<Salle> $rooms
     *
     * @return array{persisted: list<Seance>, result: array{created: int, skipped: int}}
     */
    private function generate(array $films, array $rooms, ?Salle $selectedRoom, DateTimeImmutable $start, bool $weekly = false): array
    {
        $filmRepository = $this->createMock(FilmRepository::class);
        $filmRepository->method('findProgrammableWithDuration')->willReturn($films);

        $roomRepository = $this->createMock(SalleRepository::class);
        $roomRepository->method('findBy')->willReturn($rooms);

        $seanceRepository = $this->createMock(SeanceRepository::class);
        $seanceRepository->method('findForRoomBetween')->willReturn([]);

        $persisted = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (Seance $seance) use (&$persisted): void {
            $persisted[] = $seance;
        });

        $generator = new WeeklySeanceDraftGenerator($filmRepository, $roomRepository, $seanceRepository, $entityManager);
        $result = $weekly
            ? $generator->generate($start, $selectedRoom)
            : $generator->generateDay($start);

        return ['persisted' => $persisted, 'result' => $result];
    }
}
