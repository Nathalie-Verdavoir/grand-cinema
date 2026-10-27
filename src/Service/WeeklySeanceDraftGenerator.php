<?php

namespace App\Service;

use App\Entity\Film;
use App\Entity\Salle;
use App\Entity\Seance;
use App\Enum\SeanceStatus;
use App\Repository\FilmRepository;
use App\Repository\SalleRepository;
use App\Repository\SeanceRepository;
use DateTimeImmutable;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;

final class WeeklySeanceDraftGenerator
{
    private const OPENING_HOUR = 10;
    private const CLOSING_HOUR = 24;
    private const SLOT_MINUTES = 15;
    private const BUFFER_MINUTES = 20;

    public function __construct(
        private readonly FilmRepository $filmRepository,
        private readonly SalleRepository $salleRepository,
        private readonly SeanceRepository $seanceRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{created: int, skipped: int}
     */
    public function generate(DateTimeImmutable $weekStart, ?Salle $room = null): array
    {
        $weekStart = $weekStart->setTime(0, 0);
        return $this->generateRange($weekStart, $weekStart->modify('+7 days'), $room);
    }

    /**
     * Generate drafts for one calendar day.
     *
     * @return array{created: int, skipped: int}
     */
    public function generateDay(DateTimeImmutable $day, ?Salle $room = null): array
    {
        $day = $day->setTime(0, 0);

        return $this->generateRange($day, $day->modify('+1 day'), $room);
    }

    /**
     * Fill a historical inclusive date range for fixtures, preferring shorter films
     * so each room can host as many screenings as possible.
     *
     * @return array{created: int, skipped: int}
     */
    public function generateFixtureRange(DateTimeImmutable $from, DateTimeImmutable $through): array
    {
        $from = $from->setTime(0, 0);
        $through = $through->setTime(0, 0)->modify('+1 day');

        return $this->generateRange($from, $through, null, true, true);
    }

    /**
     * @return array{created: int, skipped: int}
     */
    private function generateRange(
        DateTimeImmutable $rangeStart,
        DateTimeImmutable $rangeEnd,
        ?Salle $room = null,
        bool $includePast = false,
        bool $maximizeScreenings = false,
    ): array
    {
        $rooms = $room instanceof Salle
            ? [$room]
            : $this->salleRepository->findBy([], ['name' => 'ASC', 'id' => 'ASC']);
        $films = $this->filmRepository->findProgrammableWithDuration();
        if ($maximizeScreenings) {
            usort(
                $films,
                static fn (Film $first, Film $second): int => ($first->getDuration() ?? 0) <=> ($second->getDuration() ?? 0),
            );
        }
        $now = new DateTimeImmutable();
        $occupied = [];

        foreach ($rooms as $room) {
            $roomKey = $room->getId() ?? spl_object_id($room);
            $occupied[$roomKey] = [];
            foreach ($this->seanceRepository->findForRoomBetween($room, $rangeStart->modify('-1 day'), $rangeEnd->modify('+1 day')) as $seance) {
                $start = $seance->getDateTime();
                $duration = $seance->getFilm()?->getDuration();
                if (null === $start) {
                    continue;
                }
                $existingStart = DateTimeImmutable::createFromMutable($start);
                $existingEnd = null === $duration
                    ? $existingStart->modify('+24 hours')
                    : $existingStart->modify(sprintf('+%d minutes', $duration + self::BUFFER_MINUTES));
                for ($occupiedDate = $existingStart->setTime(0, 0); $occupiedDate < $existingEnd; $occupiedDate = $occupiedDate->modify('+1 day')) {
                    $occupied[$roomKey][$occupiedDate->format('Y-m-d')][] = [$existingStart, $existingEnd];
                }
            }
        }

        $created = 0;
        $pending = 0;
        $scheduledFilmKeys = [];
        foreach ($rooms as $room) {
            $roomKey = $room->getId() ?? spl_object_id($room);
            for ($date = $rangeStart; $date < $rangeEnd; $date = $date->modify('+1 day')) {
                $opening = $date->setTime(self::OPENING_HOUR, 0);
                $closing = $this->closingTime($opening);
                $cursor = $opening;
                if (!$includePast && $now >= $cursor) {
                    $elapsedSeconds = $now->getTimestamp() - $opening->getTimestamp();
                    $cursor = $opening->modify(sprintf('+%d seconds', (int) (ceil($elapsedSeconds / (self::SLOT_MINUTES * 60)) * self::SLOT_MINUTES * 60)));
                }
                $dateKey = $date->format('Y-m-d');
                $occupied[$roomKey][$dateKey] ??= [];

                while ($cursor < $closing) {
                    $blockingEnd = null;
                    $nextOccupied = null;
                    foreach ($occupied[$roomKey][$dateKey] as [$existingStart, $existingEnd]) {
                        if ($existingStart <= $cursor && $existingEnd > $cursor) {
                            $blockingEnd = $existingEnd;
                            break;
                        }
                        if ($existingStart > $cursor && (null === $nextOccupied || $existingStart < $nextOccupied[0])) {
                            $nextOccupied = [$existingStart, $existingEnd];
                        }
                    }

                    if (null !== $blockingEnd) {
                        $cursor = $this->ceilToSlot($blockingEnd);
                        continue;
                    }

                    $availableUntil = null !== $nextOccupied && $nextOccupied[0] < $closing
                        ? $nextOccupied[0]
                        : $closing;
                    $fittingFilms = array_values(array_filter(
                        $films,
                        static fn (Film $film): bool => null !== $film->getDuration()
                            && $film->getDuration() + self::BUFFER_MINUTES <= ($availableUntil->getTimestamp() - $cursor->getTimestamp()) / 60,
                    ));

                    if ([] === $fittingFilms) {
                        if (null === $nextOccupied) {
                            break;
                        }

                        $cursor = $this->ceilToSlot($nextOccupied[1]);
                        continue;
                    }

                    $film = $maximizeScreenings
                        ? $fittingFilms[0]
                        : $fittingFilms[random_int(0, count($fittingFilms) - 1)];
                    $managedFilm = $this->managedFilm($film);
                    $managedRoom = $this->managedRoom($room);
                    $duration = $film->getDuration();
                    $end = $cursor->modify(sprintf('+%d minutes', $duration + self::BUFFER_MINUTES));
                    $seance = (new Seance())
                        ->setFilm($managedFilm)
                        ->setSalle($managedRoom)
                        ->setDateTime(DateTime::createFromImmutable($cursor))
                        ->setStatus(
                            $includePast && $cursor < $now->setTime(0, 0)
                                ? SeanceStatus::PUBLISHED
                                : SeanceStatus::DRAFT,
                        );
                    $this->entityManager->persist($seance);
                    $occupied[$roomKey][$dateKey][] = [$cursor, $end];
                    $scheduledFilmKeys[$film->getId() ?? spl_object_id($film)] = true;
                    ++$created;
                    ++$pending;
                    if ($pending >= 500) {
                        $this->entityManager->flush();
                        $this->entityManager->clear();
                        $pending = 0;
                    }
                    $cursor = $this->ceilToSlot($end);
                }

                unset($occupied[$roomKey][$dateKey]);
            }
        }

        if ($created > 0) {
            $this->entityManager->flush();
        }

        $skipped = count(array_filter(
            $films,
            static fn (Film $film): bool => !isset($scheduledFilmKeys[$film->getId() ?? spl_object_id($film)]),
        ));

        return ['created' => $created, 'skipped' => $skipped];
    }

    private function ceilToSlot(DateTimeImmutable $dateTime): DateTimeImmutable
    {
        $secondsPastSlot = $dateTime->getTimestamp() % (self::SLOT_MINUTES * 60);

        return 0 === $secondsPastSlot
            ? $dateTime
            : $dateTime->modify(sprintf('+%d seconds', self::SLOT_MINUTES * 60 - $secondsPastSlot));
    }

    private function closingTime(DateTimeImmutable $date): DateTimeImmutable
    {
        $programmingDate = $date;
        if ((int) $date->format('G') < self::OPENING_HOUR) {
            $programmingDate = $date->modify('-1 day');
        }

        if (in_array((int) $programmingDate->format('N'), [5, 6], true)) {
            return $programmingDate->modify('+1 day')->setTime(1, 30);
        }

        return $programmingDate->setTime(self::CLOSING_HOUR, 0);
    }

    private function managedFilm(Film $film): Film
    {
        $id = $film->getId();
        if (null === $id) {
            return $film;
        }

        $managedFilm = $this->entityManager->getReference(Film::class, $id);
        if (!$managedFilm instanceof Film) {
            throw new \LogicException(sprintf('Le film %d ne peut pas être rechargé.', $id));
        }

        return $managedFilm;
    }

    private function managedRoom(Salle $room): Salle
    {
        $id = $room->getId();
        if (null === $id) {
            return $room;
        }

        $managedRoom = $this->entityManager->getReference(Salle::class, $id);
        if (!$managedRoom instanceof Salle) {
            throw new \LogicException(sprintf('La salle %d ne peut pas être rechargée.', $id));
        }

        return $managedRoom;
    }
}
