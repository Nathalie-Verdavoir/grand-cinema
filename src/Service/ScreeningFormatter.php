<?php

namespace App\Service;

use App\Entity\Seance;
use App\Enum\SeanceStatus;
use DateTimeImmutable;

final class ScreeningFormatter
{
    public function __construct(private DateFormatter $dateFormatter)
    {
    }

    /**
     * @return array{id: ?int, date: string, time: string, room: ?string, inProgress: bool}|null
     */
    public function format(Seance $seance, ?DateTimeImmutable $now = null): ?array
    {
        $dateTime = $seance->getDateTime();
        if (null === $dateTime) {
            return null;
        }

        return [
            'id' => $seance->getId(),
            'date' => sprintf(
                '%s %d %s',
                $this->dateFormatter->weekday($dateTime, true),
                $dateTime->format('j'),
                $this->dateFormatter->month($dateTime)
            ),
            'time' => $dateTime->format('H:i'),
            'room' => $seance->getSalle()?->getName(),
            'inProgress' => null !== $now && $this->isInProgress($seance, $now),
        ];
    }

    public function isUpcomingOrInProgress(Seance $seance, DateTimeImmutable $now): bool
    {
        if (SeanceStatus::PUBLISHED !== $seance->getStatus() || null === ($dateTime = $seance->getDateTime())) {
            return false;
        }

        return $this->isBookable($seance, $now) || $this->isInProgress($seance, $now);
    }

    public function isBookable(Seance $seance, DateTimeImmutable $now): bool
    {
        return SeanceStatus::PUBLISHED === $seance->getStatus()
            && null !== ($dateTime = $seance->getDateTime())
            && $dateTime > $now;
    }

    public function isInProgress(Seance $seance, DateTimeImmutable $now): bool
    {
        $dateTime = $seance->getDateTime();
        $duration = $seance->getFilm()?->getDuration();

        if (
            SeanceStatus::PUBLISHED !== $seance->getStatus()
            || null === $dateTime
            || null === $duration
            || $duration <= 0
            || $dateTime > $now
        ) {
            return false;
        }

        return DateTimeImmutable::createFromInterface($dateTime)
            ->modify(sprintf('+%d minutes', $duration)) > $now;
    }

    /**
     * @param iterable<Seance> $seances
     *
     * @return list<array{id: ?int, date: string, time: string, room: ?string, inProgress: bool}>
     */
    public function formatUpcoming(iterable $seances, DateTimeImmutable $now, ?int $limit = null): array
    {
        $upcoming = [];
        foreach ($seances as $seance) {
            $dateTime = $seance->getDateTime();
            if (
                null === $dateTime
                || !$this->isUpcomingOrInProgress($seance, $now)
            ) {
                continue;
            }

            $upcoming[] = $seance;
        }

        usort(
            $upcoming,
            static fn (Seance $first, Seance $second): int => $first->getDateTime() <=> $second->getDateTime()
        );

        if (null !== $limit) {
            $upcoming = array_slice($upcoming, 0, $limit);
        }

        return array_values(array_filter(
            array_map(fn (Seance $seance): ?array => $this->format($seance, $now), $upcoming)
        ));
    }
}
