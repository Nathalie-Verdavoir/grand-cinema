<?php

namespace App\Controller\Admin;

use App\Entity\Film;
use App\Entity\Salle;
use App\Entity\Seance;
use App\Repository\FilmRepository;
use App\Repository\SalleRepository;
use App\Repository\SeanceRepository;
use DateTimeImmutable;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

class SeanceCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly FilmRepository $filmRepository,
        private readonly SalleRepository $salleRepository,
        private readonly SeanceRepository $seanceRepository,
        private readonly RequestStack $requestStack,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Seance::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Séance')
            ->setEntityLabelInPlural('Séances')
            ->setPaginatorPageSize(10)
            ->overrideTemplate('crud/index', 'admin/seance/planner.html.twig');
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
        $weekStart = $this->getWeekStart($request?->query->get('week'));
        $weekEnd = $weekStart->modify('+7 days');
        $rooms = $this->salleRepository->findBy([], ['name' => 'ASC']);
        $roomId = filter_var($request?->query->get('salle'), FILTER_VALIDATE_INT);
        $selectedRoom = false !== $roomId ? $this->salleRepository->find($roomId) : null;

        if (!$selectedRoom instanceof Salle) {
            $selectedRoom = $rooms[0] ?? null;
        }

        $films = $this->filmRepository->findBy(['programmable' => true], ['title' => 'ASC']);
        $filmEditUrls = [];
        foreach ($films as $film) {
            if (null === $film->getDuration()) {
                $filmEditUrls[$film->getId()] = $this->adminUrlGenerator
                    ->setController(FilmCrudController::class)
                    ->setAction(Action::EDIT)
                    ->setEntityId($film->getId())
                    ->generateUrl();
            }
        }

        $seances = $selectedRoom instanceof Salle
            ? $this->seanceRepository->findForRoomBetween($selectedRoom, $weekStart->modify('-1 day'), $weekEnd)
            : [];

        $weekDays = [];
        $dayNames = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
        $eventsByDay = [];
        $now = new DateTimeImmutable('now');
        foreach ($dayNames as $index => $dayName) {
            $dayStart = $weekStart->modify(sprintf('+%d days', $index));
            $dayEnd = $dayStart->modify('+1 day');
            $blockedSlots = 0;
            if ($dayStart->format('Y-m-d') < $now->format('Y-m-d')) {
                $blockedSlots = 48;
            } elseif ($dayStart->format('Y-m-d') === $now->format('Y-m-d')) {
                $minutesSinceMidnight = ((int) $now->format('G') * 60) + (int) $now->format('i');
                $blockedSlots = min(48, (int) ceil($minutesSinceMidnight / 30));
            }

            $weekDays[] = [
                'date' => $dayStart->format('Y-m-d'),
                'label' => $dayName,
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
                    'start' => $startsAt->format('H:i'),
                    'topMinutes' => max(0, (int) (($visibleStart->getTimestamp() - $dayStart->getTimestamp()) / 60)),
                    'heightMinutes' => max(20, (int) (($visibleEnd->getTimestamp() - $visibleStart->getTimestamp()) / 60)),
                    'duration' => $duration,
                    'unknownDuration' => null === $duration,
                    'continuation' => $startsAt < $dayStart,
                ];
            }
        }

        $currentWeekStart = (new DateTimeImmutable('today'))->modify('monday this week');

        $responseParameters->set('rooms', $rooms);
        $responseParameters->set('selectedRoom', $selectedRoom);
        $responseParameters->set('films', $films);
        $responseParameters->set('filmEditUrls', $filmEditUrls);
        $responseParameters->set('weekStart', $weekStart);
        $responseParameters->set('weekEnd', $weekEnd->modify('-1 day'));
        $responseParameters->set('weekDays', $weekDays);
        $responseParameters->set('eventsByDay', $eventsByDay);
        $responseParameters->set('previousWeek', $weekStart->modify('-7 days')->format('Y-m-d'));
        $responseParameters->set('nextWeek', $weekStart->modify('+7 days')->format('Y-m-d'));
        $responseParameters->set('isCurrentWeek', $weekStart->format('Y-m-d') === $currentWeekStart->format('Y-m-d'));

        return $responseParameters;
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
