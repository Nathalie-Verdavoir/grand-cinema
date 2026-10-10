<?php

namespace App\Controller;

use App\Repository\FilmRepository;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AccueilController extends AbstractController
{

    #[Route('/', name: 'app_accueil', methods: ['GET'])]
    public function slide(FilmRepository $filmRepository): Response
    {
        $films = $filmRepository->findInCurrentProgram();
        $now = new DateTimeImmutable();
        $endOfWeek = $now->modify('sunday this week')->setTime(23, 59, 59, 999999);
        $screeningsThisWeek = [];
        $screeningsAfterThisWeek = [];
        $weekdays = [1 => 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];
        $months = [1 => 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

        foreach ($films as $film) {
            $filmId = $film->getId();
            if (null === $filmId) {
                continue;
            }

            foreach ($film->getSeances() as $seance) {
                $dateTime = $seance->getDateTime();
                if (null === $dateTime || $dateTime < $now) {
                    continue;
                }

                $screening = [
                    'dateTime' => $dateTime,
                    'room' => $seance->getSalle()?->getName(),
                ];
                if ($dateTime <= $endOfWeek) {
                    $screeningsThisWeek[$filmId][] = $screening;
                } else {
                    $screeningsAfterThisWeek[$filmId][] = $screening;
                }
            }
        }

        foreach ($films as $film) {
            $filmId = $film->getId();
            if (null === $filmId) {
                continue;
            }

            $screeningsThisWeek[$filmId] = $this->formatScreenings(
                $screeningsThisWeek[$filmId] ?? [],
                $weekdays,
                $months,
                5
            );
            $screeningsAfterThisWeek[$filmId] = $this->formatScreenings(
                $screeningsAfterThisWeek[$filmId] ?? [],
                $weekdays,
                $months,
                3
            );
        }

        return $this->render('accueil/index.html.twig', [
            'films' => $films,
            'screeningsThisWeek' => $screeningsThisWeek,
            'screeningsAfterThisWeek' => $screeningsAfterThisWeek,
        ]);
    }

    private function formatScreenings(array $screenings, array $weekdays, array $months, int $limit): array
    {
        usort(
            $screenings,
            static fn (array $first, array $second): int => $first['dateTime'] <=> $second['dateTime']
        );

        return array_map(
            static function (array $screening) use ($weekdays, $months): array {
                $dateTime = $screening['dateTime'];

                return [
                    'date' => sprintf(
                        '%s %d %s',
                        $weekdays[(int) $dateTime->format('N')],
                        $dateTime->format('j'),
                        $months[(int) $dateTime->format('n')]
                    ),
                    'time' => $dateTime->format('H:i'),
                    'room' => $screening['room'],
                ];
            },
            array_slice($screenings, 0, $limit)
        );
    }
}
