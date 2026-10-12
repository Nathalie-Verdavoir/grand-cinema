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
            foreach ($this->seanceRepository->findForRoomBetween($room, $rangeStart->modify('-1 day'), $rangeEnd) as $seance) {
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
        $skipped = 0;
        foreach ($films as $film) {
            $duration = $film->getDuration();
            if (null === $duration || [] === $rooms) {
                ++$skipped;
                continue;
            }

            $scheduled = false;
            foreach ($this->candidateSlots($rangeStart, $rangeEnd, $now) as $slot) {
                foreach ($rooms as $room) {
                    $roomKey = $room->getId() ?? spl_object_id($room);
                    $end = $slot->modify(sprintf('+%d minutes', $duration + self::BUFFER_MINUTES));
                    if ($end > $this->closingTime($slot) || $this->overlaps($occupied[$roomKey], $slot, $end)) {
                        continue;
                    }

                    $seance = (new Seance())
                        ->setFilm($film)
                        ->setSalle($room)
                        ->setDateTime(DateTime::createFromImmutable($slot))
                        ->setStatus(SeanceStatus::DRAFT);
                    $this->entityManager->persist($seance);
                    $occupied[$roomKey][] = [$slot, $end];
                    ++$created;
                    $scheduled = true;
                    break 2;
                }
            }
            if (!$scheduled) {
                ++$skipped;
            }
        }

        if ($created > 0) {
            $this->entityManager->flush();
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * @return \Generator<int, DateTimeImmutable>
     */
    private function candidateSlots(DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd, DateTimeImmutable $now): \Generator
    {
        for ($date = $rangeStart; $date < $rangeEnd; $date = $date->modify('+1 day')) {
            $opening = $date->setTime(self::OPENING_HOUR, 0);
            $closing = $this->closingTime($opening);
            for ($slot = $opening; $slot < $closing; $slot = $slot->modify(sprintf('+%d minutes', self::SLOT_MINUTES))) {
                if ($slot > $now) {
                    yield $slot;
                }
            }
        }
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

    /**
     * @param list<array{DateTimeImmutable, DateTimeImmutable}> $occupied
     */
    private function overlaps(array $occupied, DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        foreach ($occupied as [$existingStart, $existingEnd]) {
            if ($start < $existingEnd && $end > $existingStart) {
                return true;
            }
        }

        return false;
    }
}
