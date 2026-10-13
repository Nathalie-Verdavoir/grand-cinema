<?php

namespace App\Controller\Admin;

use App\Entity\Film;
use App\Entity\Salle;
use App\Entity\Seance;
use App\Enum\SeanceStatus;
use App\Repository\FilmRepository;
use App\Repository\SalleRepository;
use App\Repository\SeanceRepository;
use App\Service\DateFormatter;
use App\Service\ScreeningAvailability;
use DateTimeImmutable;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Attribute\Route;

class SeanceCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly FilmRepository $filmRepository,
        private readonly SalleRepository $salleRepository,
        private readonly SeanceRepository $seanceRepository,
        private readonly RequestStack $requestStack,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly DateFormatter $dateFormatter,
        private readonly ScreeningAvailability $screeningAvailability,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Seance::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        $isPlanningFragmentRequest = 'XMLHttpRequest' === $this->requestStack->getCurrentRequest()?->headers->get('X-Requested-With');

        return $crud
            ->setEntityLabelInSingular('Séance')
            ->setEntityLabelInPlural('Séances')
            ->setPaginatorPageSize(10)
            ->overrideTemplate('crud/index', $isPlanningFragmentRequest
                ? 'admin/seance/planner_fragment.html.twig'
                : 'admin/seance/planner.html.twig');
    }

    #[Route('/admin/seances/planner-fragment', name: 'admin_seance_planner_fragment', methods: ['GET'])]
    public function plannerFragment(): Response
    {
        $responseParameters = KeyValueStore::new(['pageName' => Crud::PAGE_INDEX]);
        $responseParameters = $this->configureResponseParameters($responseParameters);

        return $this->render('admin/seance/planner_fragment.html.twig', $responseParameters->all());
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('film', 'Film')
            ->setFormTypeOption('choice_label', 'title');
        yield DateTimeField::new('dateTime', 'Début')
            ->setFormat('dd/MM/yyyy HH:mm');
        yield AssociationField::new('salle', 'Salle')
            ->setFormTypeOption('choice_label', 'name');
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }

        $request = $this->requestStack->getCurrentRequest();
        $isDailyView = 'day' === $request?->query->get('view');
        $now = new DateTimeImmutable('now');
        $weekStart = $this->getWeekStart($request?->query->get('week'));
        $weekEnd = $weekStart->modify('+7 days');
        $rooms = $this->salleRepository->findBy([], ['name' => 'ASC']);
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
            $filmEditUrl = $this->getFilmEditUrl($film);
            if (null !== $filmEditUrl) {
                $filmEditUrls[$film->getId()] = $filmEditUrl;
            }
        }

        $seances = !$isDailyView && $selectedRoom instanceof Salle
            ? $this->seanceRepository->findForRoomBetween($selectedRoom, $weekStart->modify('-1 day'), $weekEnd)
            : [];
        $selectedDate = $this->getSelectedDate($request?->query->get('day'));
        $dayEnd = $selectedDate->modify('+1 day');
        $dailyEventsByRoom = [];
        foreach ($rooms as $room) {
            $dailyEventsByRoom[$room->getId()] = [];
        }
        if ($isDailyView) {
            foreach ($this->seanceRepository->findBetween($selectedDate, $dayEnd) as $seance) {
                $startsAt = $seance->getDateTime();
                $room = $seance->getSalle();
                if (null === $startsAt || !$room instanceof Salle) {
                    continue;
                }
                $start = DateTimeImmutable::createFromMutable($startsAt);
                $duration = $seance->getFilm()?->getDuration();
                $dailyEventsByRoom[$room->getId()][] = $this->buildEvent($seance, $start, $selectedDate, $dayEnd, $now);
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

            foreach ($seances as $seance) {
                $seanceDateTime = $seance->getDateTime();
                if (null === $seanceDateTime) {
                    continue;
                }

                $startsAt = DateTimeImmutable::createFromMutable($seanceDateTime);
                $duration = $seance->getFilm()?->getDuration();
                $endsAt = $startsAt->modify(sprintf('+%d minutes', ($duration ?? 0) + 20));

                if ($startsAt >= $dayEnd || $endsAt <= $dayStart) {
                    continue;
                }

                $visibleStart = $startsAt < $dayStart ? $dayStart : $startsAt;
                $visibleEnd = $endsAt > $dayEnd ? $dayEnd : $endsAt;
                $eventsByDay[$dayStart->format('Y-m-d')][] = [
                    'id' => $seance->getId(),
                    'title' => $seance->getFilm()?->getTitle() ?? 'Film inconnu',
                    'filmEditUrl' => $this->getFilmEditUrl($seance->getFilm()),
                    'image' => $seance->getFilm()?->getImg(),
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
                    ...$this->screeningAvailability->calculate($seance),
                ];
            }
        }

        $currentWeekStart = (new DateTimeImmutable('today'))->modify('monday this week');

        $responseParameters->set('rooms', $rooms);
        $responseParameters->set('selectedRoom', $selectedRoom);
        $responseParameters->set('films', $films);
        $responseParameters->set('hasProgrammableFilms', $hasProgrammableFilms);
        $responseParameters->set('filmEditUrls', $filmEditUrls);
        $responseParameters->set('weekStart', $weekStart);
        $responseParameters->set('weekEnd', $weekEnd->modify('-1 day'));
        $responseParameters->set('weekDays', $weekDays);
        $responseParameters->set('eventsByDay', $eventsByDay);
        $responseParameters->set('previousWeek', $weekStart->modify('-7 days')->format('Y-m-d'));
        $responseParameters->set('nextWeek', $weekStart->modify('+7 days')->format('Y-m-d'));
        $responseParameters->set('isCurrentWeek', $weekStart->format('Y-m-d') === $currentWeekStart->format('Y-m-d'));
        $responseParameters->set('isDailyView', $isDailyView);
        $responseParameters->set('selectedDate', $selectedDate);
        $responseParameters->set('previousDay', $selectedDate->modify('-1 day')->format('Y-m-d'));
        $responseParameters->set('nextDay', $selectedDate->modify('+1 day')->format('Y-m-d'));
        $responseParameters->set('isToday', $selectedDate->format('Y-m-d') === (new DateTimeImmutable('today'))->format('Y-m-d'));
        $dayBlockedSlots = $selectedDate->format('Y-m-d') < $now->format('Y-m-d') ? 96 : ($selectedDate->format('Y-m-d') === $now->format('Y-m-d') ? min(96, (int) ceil((((int) $now->format('G') * 60) + (int) $now->format('i')) / 15)) : 0);
        $responseParameters->set('dayBlockedSlots', $dayBlockedSlots);
        $responseParameters->set('dailyEventsByRoom', $dailyEventsByRoom);

        return $responseParameters;
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

    private function buildEvent(Seance $seance, DateTimeImmutable $startsAt, DateTimeImmutable $dayStart, DateTimeImmutable $dayEnd, DateTimeImmutable $now): array
    {
        $film = $seance->getFilm();
        $duration = $film?->getDuration();
        $endsAt = $startsAt->modify(sprintf('+%d minutes', ($duration ?? 0) + 20));
        $visibleStart = $startsAt < $dayStart ? $dayStart : $startsAt;
        $visibleEnd = $endsAt > $dayEnd ? $dayEnd : $endsAt;

        return [
            'id' => $seance->getId(),
            'title' => $film?->getTitle() ?? 'Film inconnu',
            'filmEditUrl' => $this->getFilmEditUrl($film),
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
            ...$this->screeningAvailability->calculate($seance),
        ];
    }

    private function getFilmEditUrl(?Film $film): ?string
    {
        if (null === $film || null === $film->getId()) {
            return null;
        }

        return $this->adminUrlGenerator
            ->setController(FilmCrudController::class)
            ->setAction(Action::EDIT)
            ->setEntityId($film->getId())
            ->generateUrl();
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
