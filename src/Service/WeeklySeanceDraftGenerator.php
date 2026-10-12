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
     * @return array{created: int, skipped: int}
     */
    private function generateRange(DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd, ?Salle $room = null): array
    {
        $rooms = $room instanceof Salle
            ? [$room]
            : $this->salleRepository->findBy([], ['name' => 'ASC', 'id' => 'ASC']);
        $films = $this->filmRepository->findProgrammableWithDuration();
        $now = new DateTimeImmutable();
        $occupied = [];

        foreach ($rooms as $room) {
            $occupied[$room->getId() ?? spl_object_id($room)] = [];
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
                $occupied[$room->getId() ?? spl_object_id($room)][] = [
                    $existingStart,
                    $existingEnd,
                ];
            }
        }

        $created = 0;
        $scheduledFilmKeys = [];
        foreach ($rooms as $room) {
            $roomKey = $room->getId() ?? spl_object_id($room);
            for ($date = $rangeStart; $date < $rangeEnd; $date = $date->modify('+1 day')) {
                $opening = $date->setTime(self::OPENING_HOUR, 0);
                $closing = $this->closingTime($opening);
                $cursor = $opening;
                if ($now >= $cursor) {
                    $elapsedSeconds = $now->getTimestamp() - $opening->getTimestamp();
                    $cursor = $opening->modify(sprintf('+%d seconds', (int) (ceil($elapsedSeconds / (self::SLOT_MINUTES * 60)) * self::SLOT_MINUTES * 60)));
                }

                while ($cursor < $closing) {
                    $blockingEnd = null;
                    $nextOccupied = null;
                    foreach ($occupied[$roomKey] as [$existingStart, $existingEnd]) {
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

                    $film = $fittingFilms[random_int(0, count($fittingFilms) - 1)];
                    $duration = $film->getDuration();
                    $end = $cursor->modify(sprintf('+%d minutes', $duration + self::BUFFER_MINUTES));
                    $seance = (new Seance())
                        ->setFilm($film)
                        ->setSalle($room)
                        ->setDateTime(DateTime::createFromImmutable($cursor))
                        ->setStatus(SeanceStatus::DRAFT);
                    $this->entityManager->persist($seance);
                    $occupied[$roomKey][] = [$cursor, $end];
                    $scheduledFilmKeys[$film->getId() ?? spl_object_id($film)] = true;
                    ++$created;
                    $cursor = $this->ceilToSlot($end);
                }
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
}
