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
        SeanceRepository $seanceRepository,
        DateFormatter $dateFormatter
    ): Response {
        $now = new DateTimeImmutable();
        $today = $now->setTime(0, 0);
        $currentWeekStart = $this->wednesdayStart($today);
        $weekStart = $this->requestedWeekStart($request->query->get('week'), $currentWeekStart);

        $weekStarts = [$currentWeekStart->format('Y-m-d') => $currentWeekStart];
        $lastAvailableWeek = $currentWeekStart;
        foreach ($seanceRepository->findPublishedDateTimesFrom($currentWeekStart) as $dateTime) {
            $scheduledWeek = $this->wednesdayStart($dateTime);
            $weekStarts[$scheduledWeek->format('Y-m-d')] = $scheduledWeek;
            if ($scheduledWeek > $lastAvailableWeek) {
                $lastAvailableWeek = $scheduledWeek;
            }
        }
        if ($weekStart > $lastAvailableWeek) {
            $lastAvailableWeek = $weekStart;
        }
        $weekStarts[$weekStart->format('Y-m-d')] = $weekStart;
        $nextWeek = $weekStart->modify('+7 days');
        $weekStarts[$nextWeek->format('Y-m-d')] = $nextWeek;
        ksort($weekStarts);

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
        $screeningsStart = $weekStart->format('Y-m-d') === $currentWeekStart->format('Y-m-d')
            ? max($weekStart, $now)
            : $weekStart;
        foreach ($seanceRepository->findPublishedBetween($screeningsStart, $weekEnd) as $seance) {
            $film = $seance->getFilm();
            $dateTime = $seance->getDateTime();
            $filmId = $film?->getId();
            if (null === $film || null === $filmId || null === $dateTime) {
                continue;
            }

            $filmSchedules[$filmId] ??= [
                'film' => $film,
                'screeningsByDay' => [],
            ];
            $filmSchedules[$filmId]['screeningsByDay'][$dateTime->format('Y-m-d')][] = $dateTime->format('H:i');
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

        return $this->render('programmation/index.html.twig', [
            'filmSchedules' => array_values($filmSchedules),
            'days' => $days,
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd->modify('-1 day'),
            'weekOptions' => $weekOptions,
            'nextWeek' => $nextWeek,
            'previousWeek' => $weekStart->modify('-7 days'),
            'currentWeekStart' => $currentWeekStart,
        ]);
    }

    #[Route('/programmation/film/{id}', name: 'app_programmation_film', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function film(int $id, FilmRepository $filmRepository, ScreeningFormatter $screeningFormatter): Response
    {
        $film = $filmRepository->findInCurrentProgramById($id);
        if (!$film instanceof Film) {
            throw $this->createNotFoundException('Ce film n’est pas actuellement programmé.');
        }

        return $this->render('programmation/film.html.twig', [
            'film' => $film,
            'screenings' => $screeningFormatter->formatUpcoming($film->getSeances(), new DateTimeImmutable()),
        ]);
    }

    private function wednesdayStart(DateTimeImmutable $date): DateTimeImmutable
    {
        $daysSinceWednesday = ((int) $date->format('N') - 3 + 7) % 7;

        return $date->setTime(0, 0)->modify(sprintf('-%d days', $daysSinceWednesday));
    }

    private function requestedWeekStart(mixed $requestedWeek, DateTimeImmutable $currentWeekStart): DateTimeImmutable
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
        ) {
            return $currentWeekStart;
        }

        return $weekStart;
    }
}
