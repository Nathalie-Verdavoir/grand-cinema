<?php

namespace App\Controller;

use App\Repository\FilmRepository;
use App\Service\ScreeningFormatter;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AccueilController extends AbstractController
{

    #[Route('/', name: 'app_accueil', methods: ['GET'])]
    public function slide(FilmRepository $filmRepository, ScreeningFormatter $screeningFormatter): Response
    {
        $films = $filmRepository->findInCurrentProgram();
        $filmsWithAvailableScreenings = [];
        $now = new DateTimeImmutable();
        $endOfWeek = $now->modify('sunday this week')->setTime(23, 59, 59, 999999);
        $screeningsThisWeek = [];
        $screeningsAfterThisWeek = [];

        foreach ($films as $film) {
            $filmId = $film->getId();
            if (null === $filmId) {
                continue;
            }

            $hasAvailableScreening = false;
            foreach ($film->getSeances() as $seance) {
                $dateTime = $seance->getDateTime();
                if (null === $dateTime || !$screeningFormatter->isUpcomingOrInProgress($seance, $now)) {
                    continue;
                }

                $screening = $screeningFormatter->format($seance, $now);
                if (null === $screening) {
                    continue;
                }
                $screening['dateTime'] = $dateTime;
                $screening['inProgress'] = $screeningFormatter->isInProgress($seance, $now);
                $hasAvailableScreening = true;
                if ($dateTime <= $endOfWeek) {
                    $screeningsThisWeek[$filmId][] = $screening;
                } else {
                    $screeningsAfterThisWeek[$filmId][] = $screening;
                }
            }

            if ($hasAvailableScreening) {
                $filmsWithAvailableScreenings[] = $film;
            }
        }

        $films = $filmsWithAvailableScreenings;
        foreach ($films as $film) {
            $filmId = $film->getId();
            if (null === $filmId) {
                continue;
            }

            $screeningsThisWeek[$filmId] = $this->formatScreenings(
                $screeningsThisWeek[$filmId] ?? [],
                5
            );
            $screeningsAfterThisWeek[$filmId] = $this->formatScreenings(
                $screeningsAfterThisWeek[$filmId] ?? [],
                3
            );
        }

        return $this->render('accueil/index.html.twig', [
            'films' => $films,
            'screeningsThisWeek' => $screeningsThisWeek,
            'screeningsAfterThisWeek' => $screeningsAfterThisWeek,
        ]);
    }

    private function formatScreenings(array $screenings, int $limit): array
    {
        usort(
            $screenings,
            static fn (array $first, array $second): int => $first['dateTime'] <=> $second['dateTime']
        );

        return array_map(
            static fn (array $screening): array => [
                'id' => $screening['id'],
                'date' => $screening['date'],
                'time' => $screening['time'],
                'room' => $screening['room'],
                'inProgress' => $screening['inProgress'],
            ],
            array_slice($screenings, 0, $limit)
        );
    }
}
