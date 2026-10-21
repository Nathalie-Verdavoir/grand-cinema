<?php

namespace App\Service;

use App\Entity\Film;
use App\Entity\Salle;
use App\Entity\Seance;
use App\Enum\SeanceStatus;
use App\Repository\FilmRepository;
use App\Repository\SalleRepository;
use App\Repository\SeanceRepository;
use App\Controller\Admin\FilmCrudController;
use DateTimeImmutable;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Request;

final class SeancePlannerPageDataProvider
{
    public function __construct(
        private readonly FilmRepository $filmRepository,
        private readonly SalleRepository $salleRepository,
        private readonly SeanceRepository $seanceRepository,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly DateFormatter $dateFormatter,
        private readonly ScreeningAvailability $screeningAvailability,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(?Request $request): array
    {
        $isDailyView = 'week' !== $request?->query->get('view');
        $now = new DateTimeImmutable('now');
        $weekStart = $this->getWeekStart($request?->query->get('week'));
        $weekEnd = $weekStart->modify('+7 days');
        $rooms = $this->salleRepository->findAll();
        usort($rooms, static fn (Salle $left, Salle $right): int => strnatcasecmp($left->getName() ?? '', $right->getName() ?? ''));
        $roomId = filter_var($request?->query->get('salle'), FILTER_VALIDATE_INT);
        $selectedRoom = null;
        if (false !== $roomId) {
            foreach ($rooms as $room) {
                if ($room->getId() === $roomId) {
                    $selectedRoom = $room;
                    break;
                }
            }
        }

        if (!$selectedRoom instanceof Salle) {
            $selectedRoom = $rooms[0] ?? null;
        }

        $films = $isDailyView ? [] : $this->filmRepository->findBy(['programmable' => true], ['title' => 'ASC']);
        $hasProgrammableFilms = $isDailyView
            ? $this->filmRepository->hasProgrammableFilms()
            : [] !== $films;
        $filmEditUrls = [];
        foreach ($films as $film) {
            $this->getFilmEditUrl($film, $filmEditUrls);
        }

        $selectedDate = $this->getSelectedDate($request?->query->get('day'));
        $dayEnd = $selectedDate->modify('+1 day');
        $seances = $isDailyView
            ? $this->seanceRepository->findForPlannerBetween($selectedDate, $dayEnd)
            : ($selectedRoom instanceof Salle
                ? $this->seanceRepository->findForPlannerRoomBetween($selectedRoom, $weekStart->modify('-1 day'), $weekEnd)
                : []);
        $seanceIds = array_values(array_filter(
            array_map(static fn (Seance $seance): ?int => $seance->getId(), $seances),
            static fn (?int $id): bool => null !== $id
        ));
        $occupancyBySeance = $this->seanceRepository->findOccupancyBySeanceIds($seanceIds);
        $dailyEventsByRoom = [];
        foreach ($rooms as $room) {
            $roomId = $room->getId();
            if (null !== $roomId) {
                $dailyEventsByRoom[$roomId] = [];
            }
        }
        if ($isDailyView) {
            foreach ($seances as $seance) {
                $startsAt = $seance->getDateTime();
                $room = $seance->getSalle();
                if (null === $startsAt || !$room instanceof Salle) {
                    continue;
                }

                $dailyEventsByRoom[$room->getId()][] = $this->buildEvent(
                    $seance,
                    DateTimeImmutable::createFromMutable($startsAt),
                    $selectedDate,
                    $dayEnd,
                    $now,
                    $filmEditUrls,
                    $occupancyBySeance[$seance->getId() ?? 0]['reservedSeats'] ?? 0,
                    $occupancyBySeance[$seance->getId() ?? 0]['occupancyPercent'] ?? 0,
                );
            }
        }

        $weekDays = [];
        $eventsByDay = [];
        for ($index = 0; $index < 7; ++$index) {
            $dayStart = $weekStart->modify(sprintf('+%d days', $index));
            $dayEnd = $dayStart->modify('+1 day');
            $blockedSlots = 0;
            if ($dayStart->format('Y-m-d') < $now->format('Y-m-d')) {
                $blockedSlots = 96;
            } elseif ($dayStart->format('Y-m-d') === $now->format('Y-m-d')) {
                $minutesSinceMidnight = ((int) $now->format('G') * 60) + (int) $now->format('i');
                $blockedSlots = min(96, (int) ceil($minutesSinceMidnight / 15));
            }

            $weekDays[] = [
                'date' => $dayStart->format('Y-m-d'),
                'label' => $this->dateFormatter->weekday($dayStart),
                'shortDate' => $dayStart->format('d/m'),
                'blockedSlots' => $blockedSlots,
            ];
            $eventsByDay[$dayStart->format('Y-m-d')] = [];
        }

        foreach ($seances as $seance) {
            $seanceDateTime = $seance->getDateTime();
            if (null === $seanceDateTime) {
                continue;
            }

            $startsAt = DateTimeImmutable::createFromMutable($seanceDateTime);
            $duration = $seance->getFilm()?->getDuration();
            $endsAt = $startsAt->modify(sprintf('+%d minutes', ($duration ?? 0) + 20));
            $firstDay = max(0, (int) $weekStart->diff($startsAt->setTime(0, 0))->format('%r%a'));
            $lastDay = min(6, (int) $weekStart->diff($endsAt->modify('-1 second')->setTime(0, 0))->format('%r%a'));

            for ($dayIndex = $firstDay; $dayIndex <= $lastDay; ++$dayIndex) {
                $dayStart = $weekStart->modify(sprintf('+%d days', $dayIndex));
                $dayEnd = $dayStart->modify('+1 day');
                $eventsByDay[$dayStart->format('Y-m-d')][] = $this->buildEvent(
                    $seance,
                    $startsAt,
                    $dayStart,
                    $dayEnd,
                    $now,
                    $filmEditUrls,
                    $occupancyBySeance[$seance->getId() ?? 0]['reservedSeats'] ?? 0,
                    $occupancyBySeance[$seance->getId() ?? 0]['occupancyPercent'] ?? 0,
                );
            }
        }

        $currentWeekStart = (new DateTimeImmutable('today'))->modify('monday this week');
        $dayBlockedSlots = $selectedDate->format('Y-m-d') < $now->format('Y-m-d')
            ? 96
            : ($selectedDate->format('Y-m-d') === $now->format('Y-m-d')
                ? min(96, (int) ceil((((int) $now->format('G') * 60) + (int) $now->format('i')) / 15))
                : 0);

        return [
            'rooms' => $rooms,
            'selectedRoom' => $selectedRoom,
            'films' => $films,
            'hasProgrammableFilms' => $hasProgrammableFilms,
            'filmEditUrls' => $filmEditUrls,
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd->modify('-1 day'),
            'weekDays' => $weekDays,
            'eventsByDay' => $eventsByDay,
            'previousWeek' => $weekStart->modify('-7 days')->format('Y-m-d'),
            'nextWeek' => $weekStart->modify('+7 days')->format('Y-m-d'),
            'isCurrentWeek' => $weekStart->format('Y-m-d') === $currentWeekStart->format('Y-m-d'),
            'isDailyView' => $isDailyView,
            'selectedDate' => $selectedDate,
            'previousDay' => $selectedDate->modify('-1 day')->format('Y-m-d'),
            'nextDay' => $selectedDate->modify('+1 day')->format('Y-m-d'),
            'isToday' => $selectedDate->format('Y-m-d') === (new DateTimeImmutable('today'))->format('Y-m-d'),
            'dayBlockedSlots' => $dayBlockedSlots,
            'dailyEventsByRoom' => $dailyEventsByRoom,
        ];
    }

    private function getSelectedDate(mixed $day): DateTimeImmutable
    {
        if (is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && (false === $errors || (0 === $errors['warning_count'] && 0 === $errors['error_count'])) && $date->format('Y-m-d') === $day) {
                return $date;
            }
        }

        return new DateTimeImmutable('today');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEvent(
        Seance $seance,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $dayStart,
        DateTimeImmutable $dayEnd,
        DateTimeImmutable $now,
        array &$filmEditUrls,
        int $reservedSeats,
        int $occupancyPercent,
    ): array
    {
        $film = $seance->getFilm();
        $duration = $film?->getDuration();
        $endsAt = $startsAt->modify(sprintf('+%d minutes', ($duration ?? 0) + 20));
        $visibleStart = $startsAt < $dayStart ? $dayStart : $startsAt;
        $visibleEnd = $endsAt > $dayEnd ? $dayEnd : $endsAt;

        return [
            'id' => $seance->getId(),
            'title' => $film?->getTitle() ?? 'Film inconnu',
            'filmEditUrl' => $this->getFilmEditUrl($film, $filmEditUrls),
            'image' => $film?->getImg(),
            'start' => $startsAt->format('H:i'),
            'startTimestamp' => $startsAt->getTimestamp(),
            'endTimestamp' => $endsAt->getTimestamp(),
            'topMinutes' => max(0, (int) (($visibleStart->getTimestamp() - $dayStart->getTimestamp()) / 60)),
            'heightMinutes' => max(20, (int) (($visibleEnd->getTimestamp() - $visibleStart->getTimestamp()) / 60)),
            'duration' => $duration,
            'unknownDuration' => null === $duration,
            'continuation' => $startsAt < $dayStart,
            'canDelete' => $startsAt > $now,
            'status' => $seance->getStatus()->value,
            'statusLabel' => $seance->getStatus()->getLabel(),
            'canPublish' => $seance->getStatus() === SeanceStatus::DRAFT,
            'ended' => null !== $duration && $endsAt <= $now,
            ...$this->screeningAvailability->calculate($seance, $reservedSeats, $occupancyPercent),
        ];
    }

    /**
     * @param array<int, string> $filmEditUrls
     */
    private function getFilmEditUrl(?Film $film, array &$filmEditUrls): ?string
    {
        if (null === $film || null === $film->getId()) {
            return null;
        }

        $filmId = $film->getId();
        if (!isset($filmEditUrls[$filmId])) {
            $filmEditUrls[$filmId] = $this->adminUrlGenerator
                ->setController(FilmCrudController::class)
                ->setAction(Action::EDIT)
                ->setEntityId($filmId)
                ->generateUrl();
        }

        return $filmEditUrls[$filmId];
    }

    private function getWeekStart(mixed $week): DateTimeImmutable
    {
        if (is_string($week) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $week)) {
            $requestedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $week);
            $errors = DateTimeImmutable::getLastErrors();

            if ($requestedDate && (false === $errors || (0 === $errors['warning_count'] && 0 === $errors['error_count'])) && $requestedDate->format('Y-m-d') === $week) {
                return $requestedDate->modify('monday this week')->setTime(0, 0);
            }
        }

        return (new DateTimeImmutable('today'))->modify('monday this week');
    }
}
