<?php

namespace App\Service;

use App\Repository\AdminKpiRepository;
use DateTimeImmutable;
use DateTimeInterface;

final class AdminKpiCalculator
{
    public function __construct(
        private readonly AdminKpiRepository $kpiRepository,
        private readonly DateFormatter $dateFormatter,
    ) {
    }

    /**
     * @return array{
     *     entries: int,
     *     revenue: float,
     *     averageRevenuePerEntry: float,
     *     occupancyPercent: float,
     *     activeScreens: int,
     *     revenuePerScreen: float,
     *     onlineReservations: int,
     *     counterReservations: int,
     *     onlinePercent: float,
     *     counterPercent: float,
     *     occupancyChartPercent: float,
     *     salesByFilm: list<array{title: string, entries: int, revenue: float, barPercent: float}>,
     *     salesByWeekday: list<array{label: string, entries: int, barPercent: float}>,
     *     salesByTimeOfDay: list<array{label: string, entries: int, barPercent: float}>
     * }
     */
    public function calculate(DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        $entries = 0;
        $revenue = 0.0;
        $reservations = 0;
        $counterReservations = 0;
        $entriesByWeekday = array_fill(1, 7, 0);
        $weekReference = new DateTimeImmutable('2020-01-06');
        $entriesByTimeOfDay = [
            '10 h – 12 h' => 0,
            '12 h – 14 h' => 0,
            '14 h – 16 h' => 0,
            '16 h – 18 h' => 0,
            '18 h – 20 h' => 0,
            '20 h – 22 h' => 0,
            '22 h – 00 h' => 0,
        ];
        $entriesBeforeTen = 0;

        foreach ($this->kpiRepository->findSalesByScreening($from, $until) as $row) {
            $screeningDateTime = $this->toDateTime($row['screeningDate']);
            $screeningEntries = (int) $row['entries'];
            $entries += $screeningEntries;
            $revenue += (float) ($row['revenue'] ?? 0);
            $screeningReservations = (int) $row['reservations'];
            $reservations += $screeningReservations;
            $counterReservations += (int) $row['counterReservations'];
            $weekday = (int) $screeningDateTime->format('N');
            $entriesByWeekday[$weekday] += $screeningEntries;

            $timeBucket = (int) $row['timeBucket'];
            if (0 === $timeBucket) {
                $entriesBeforeTen += $screeningEntries;
            } else {
                if ($timeBucket < 1 || $timeBucket > 7) {
                    throw new \LogicException(sprintf('Tranche horaire KPI invalide : %d.', $timeBucket));
                }
                $startHour = 8 + 2 * $timeBucket;
                $timeOfDay = sprintf('%02d h – %02d h', $startHour, ($startHour + 2) % 24);
                $entriesByTimeOfDay[$timeOfDay] += $screeningEntries;
            }
        }

        $filmSales = [];
        foreach ($this->kpiRepository->findSalesByFilm($from, $until) as $row) {
            $filmSales[] = [
                'title' => $row['filmTitle'],
                'entries' => (int) $row['entries'],
                'revenue' => (float) ($row['revenue'] ?? 0),
                'barPercent' => 0.0,
            ];
        }
        $maxFilmEntries = max(array_column($filmSales, 'entries') ?: [0]);
        foreach ($filmSales as &$filmSale) {
            $filmSale['barPercent'] = $maxFilmEntries > 0 ? ($filmSale['entries'] / $maxFilmEntries) * 100 : 0.0;
        }
        unset($filmSale);

        $capacity = 0;
        $rooms = [];
        foreach ($this->kpiRepository->findPublishedScreeningCapacities($from, $until) as $row) {
            $capacity += (int) $row['capacity'];
            $rooms[(string) $row['roomId']] = true;
        }

        $activeScreens = count($rooms);
        $onlineReservations = max(0, $reservations - $counterReservations);
        $weekdayRows = [];
        $maxWeekdayEntries = max($entriesByWeekday);
        foreach ($entriesByWeekday as $weekday => $count) {
            $weekdayRows[] = [
                'label' => $this->dateFormatter->weekday($weekReference->setISODate(2020, 2, $weekday)),
                'entries' => $count,
                'barPercent' => $maxWeekdayEntries > 0 ? ($count / $maxWeekdayEntries) * 100 : 0.0,
            ];
        }

        if ($entriesBeforeTen > 0) {
            $entriesByTimeOfDay = ['Avant 10 h' => $entriesBeforeTen] + $entriesByTimeOfDay;
        }

        $timeOfDayRows = [];
        $maxTimeOfDayEntries = max($entriesByTimeOfDay);
        foreach ($entriesByTimeOfDay as $label => $count) {
            $timeOfDayRows[] = [
                'label' => $label,
                'entries' => $count,
                'barPercent' => $maxTimeOfDayEntries > 0 ? ($count / $maxTimeOfDayEntries) * 100 : 0.0,
            ];
        }

        return [
            'entries' => $entries,
            'revenue' => $revenue,
            'averageRevenuePerEntry' => $entries > 0 ? $revenue / $entries : 0.0,
            'occupancyPercent' => $capacity > 0 ? ($entries / $capacity) * 100 : 0.0,
            'activeScreens' => $activeScreens,
            'revenuePerScreen' => $activeScreens > 0 ? $revenue / $activeScreens : 0.0,
            'onlineReservations' => $onlineReservations,
            'counterReservations' => $counterReservations,
            'onlinePercent' => $reservations > 0 ? ($onlineReservations / $reservations) * 100 : 0.0,
            'counterPercent' => $reservations > 0 ? ($counterReservations / $reservations) * 100 : 0.0,
            'occupancyChartPercent' => min(100.0, $capacity > 0 ? ($entries / $capacity) * 100 : 0.0),
            'salesByFilm' => $filmSales,
            'salesByWeekday' => $weekdayRows,
            'salesByTimeOfDay' => $timeOfDayRows,
        ];
    }

    private function toDateTime(DateTimeInterface|string $value): DateTimeImmutable
    {
        return $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable($value);
    }
}
