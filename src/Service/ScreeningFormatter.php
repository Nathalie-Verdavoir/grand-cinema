<?php

namespace App\Service;

use App\Entity\Seance;
use App\Enum\SeanceStatus;
use DateTimeImmutable;

final class ScreeningFormatter
{
    /**
     * @return array{date: string, time: string, room: ?string}|null
     */
    public function format(Seance $seance): ?array
    {
        $dateTime = $seance->getDateTime();
        if (null === $dateTime) {
            return null;
        }

        $weekdays = [1 => 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];
        $months = [1 => 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

        return [
            'date' => sprintf(
                '%s %d %s',
                $weekdays[(int) $dateTime->format('N')],
                $dateTime->format('j'),
                $months[(int) $dateTime->format('n')]
            ),
            'time' => $dateTime->format('H:i'),
            'room' => $seance->getSalle()?->getName(),
        ];
    }

    /**
     * @param iterable<Seance> $seances
     *
     * @return list<array{date: string, time: string, room: ?string}>
     */
    public function formatUpcoming(iterable $seances, DateTimeImmutable $now, ?int $limit = null): array
    {
        $upcoming = [];
        foreach ($seances as $seance) {
            $dateTime = $seance->getDateTime();
            if (
                SeanceStatus::PUBLISHED !== $seance->getStatus()
                || null === $dateTime
                || $dateTime < $now
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
            array_map(fn (Seance $seance): ?array => $this->format($seance), $upcoming)
        ));
    }
}
