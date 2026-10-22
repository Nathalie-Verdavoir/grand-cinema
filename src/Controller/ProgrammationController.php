<?php

namespace App\Controller;

use App\Entity\Film;
use App\Repository\FilmRepository;
use App\Repository\SeanceRepository;
use App\Service\DateFormatter;
use App\Service\ScreeningFormatter;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ProgrammationController extends AbstractController
{
    #[Route('/programmation', name: 'app_programmation', methods: ['GET'])]
    public function index(
        Request $request,
        FilmRepository $filmRepository,
        SeanceRepository $seanceRepository,
        DateFormatter $dateFormatter,
        ScreeningFormatter $screeningFormatter
    ): Response {
        $now = new DateTimeImmutable();
        $today = $now->setTime(0, 0);
        $currentWeekStart = $this->wednesdayStart($today);
        $weekStarts = [$currentWeekStart->format('Y-m-d') => $currentWeekStart];
        foreach ($seanceRepository->findPublishedDateTimesFrom($currentWeekStart) as $dateTime) {
            $scheduledWeek = $this->wednesdayStart($dateTime);
            $weekStarts[$scheduledWeek->format('Y-m-d')] = $scheduledWeek;
        }
        ksort($weekStarts);

        $weekStart = $this->requestedWeekStart($request->query->get('week'), $currentWeekStart, $weekStarts);
        $weekStartValues = array_values($weekStarts);
        $weekStartIndex = array_search($weekStart->format('Y-m-d'), array_keys($weekStarts), true);
        if (false === $weekStartIndex) {
            throw new \LogicException('The selected week must be present in the available weeks.');
        }
        $previousWeek = $weekStartIndex > 0 ? $weekStartValues[$weekStartIndex - 1] : null;
        $nextWeek = $weekStartIndex < count($weekStartValues) - 1 ? $weekStartValues[$weekStartIndex + 1] : null;
        $weekEnd = $weekStart->modify('+7 days');
        $days = [];
        for ($dayOffset = 0; $dayOffset < 7; ++$dayOffset) {
            $date = $weekStart->modify(sprintf('+%d days', $dayOffset));
            $days[] = [
                'value' => $date->format('Y-m-d'),
                'label' => strtoupper($dateFormatter->weekday($date, true)),
                'number' => $date->format('d'),
                'isToday' => $date->format('Y-m-d') === $today->format('Y-m-d'),
            ];
        }

        $filmSchedules = [];
        $maximumDuration = $filmRepository->findMaximumDurationMinutes();
        $screeningsStart = max(
            $weekStart->modify(sprintf('-%d minutes', $maximumDuration)),
            $now->modify(sprintf('-%d minutes', $maximumDuration)),
        );
        foreach ($seanceRepository->findPublishedBetween($screeningsStart, $weekEnd) as $seance) {
            $film = $seance->getFilm();
            $dateTime = $seance->getDateTime();
            $filmId = $film?->getId();
            if (null === $film || null === $filmId || null === $dateTime) {
                continue;
            }

            if ($dateTime < $weekStart || !$screeningFormatter->isUpcomingOrInProgress($seance, $now)) {
                continue;
            }

            $filmSchedules[$filmId] ??= [
                'film' => $film,
                'screeningsByDay' => [],
            ];
            $filmSchedules[$filmId]['screeningsByDay'][$dateTime->format('Y-m-d')][] = [
                'id' => $seance->getId(),
                'time' => $dateTime->format('H:i'),
                'inProgress' => $screeningFormatter->isInProgress($seance, $now),
            ];
        }

        $weekOptions = [];
        foreach ($weekStarts as $optionStart) {
            $weekOptions[] = [
                'value' => $optionStart->format('Y-m-d'),
                'label' => sprintf(
                    'Semaine du %s au %s',
                    $optionStart->format('d/m'),
                    $optionStart->modify('+6 days')->format('d/m')
                ),
            ];
        }

        $screeningsContext = [
            'filmSchedules' => array_values($filmSchedules),
            'days' => $days,
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd->modify('-1 day'),
            'weekOptions' => $weekOptions,
            'nextWeek' => $nextWeek,
            'previousWeek' => $previousWeek,
            'currentWeekStart' => $currentWeekStart,
        ];

        if ($request->isXmlHttpRequest()) {
            $response = $this->render('programmation/_screenings.html.twig', $screeningsContext);
            $response->setSharedMaxAge(300);
            $response->setVary('X-Requested-With');

            return $response;
        }

        $featuredFilms = [];
        $featuredFilmIds = [];
        foreach ($filmRepository->findInCurrentProgram() as $film) {
            $filmId = $film->getId();
            if (null === $filmId || isset($featuredFilmIds[$filmId])) {
                continue;
            }

            if ([] === $screeningFormatter->formatUpcoming($film->getSeances(), $now)) {
                continue;
            }

            $featuredFilmIds[$filmId] = true;
            $featuredFilms[] = $film;
        }

        $response = $this->render('programmation/index.html.twig', [
            ...$screeningsContext,
            'featuredFilms' => $featuredFilms,
        ]);
        $response->setSharedMaxAge(300);
        $response->setVary('X-Requested-With');

        return $response;
    }

    #[Route('/programmation/film/{id}', name: 'app_programmation_film', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function film(int $id, FilmRepository $filmRepository, ScreeningFormatter $screeningFormatter): Response
    {
        $film = $filmRepository->findInCurrentProgramById($id);
        $now = new DateTimeImmutable();
        $screenings = $film instanceof Film
            ? $screeningFormatter->formatUpcoming($film->getSeances(), $now)
            : [];
        if (!$film instanceof Film || [] === $screenings) {
            throw $this->createNotFoundException('Ce film n’est pas actuellement programmé.');
        }

        $response = $this->render('programmation/film.html.twig', [
            'film' => $film,
            'screenings' => $screenings,
        ]);
        $response->setSharedMaxAge(60);

        return $response;
    }

    private function wednesdayStart(DateTimeImmutable $date): DateTimeImmutable
    {
        $daysSinceWednesday = ((int) $date->format('N') - 3 + 7) % 7;

        return $date->setTime(0, 0)->modify(sprintf('-%d days', $daysSinceWednesday));
    }

    /**
     * @param array<string, DateTimeImmutable> $availableWeekStarts
     */
    private function requestedWeekStart(
        mixed $requestedWeek,
        DateTimeImmutable $currentWeekStart,
        array $availableWeekStarts
    ): DateTimeImmutable
    {
        if (!is_string($requestedWeek) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $requestedWeek)) {
            return $currentWeekStart;
        }

        $weekStart = DateTimeImmutable::createFromFormat('!Y-m-d', $requestedWeek);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            false === $weekStart
            || (false !== $errors && (0 < $errors['warning_count'] || 0 < $errors['error_count']))
            || $weekStart->format('Y-m-d') !== $requestedWeek
            || '3' !== $weekStart->format('N')
            || $weekStart < $currentWeekStart
            || !isset($availableWeekStarts[$requestedWeek])
        ) {
            return $currentWeekStart;
        }

        return $weekStart;
    }
}
