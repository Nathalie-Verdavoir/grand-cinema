<?php

namespace App\Controller;

use App\Entity\Film;
use App\Repository\FilmRepository;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ProgrammationController extends AbstractController
{
    #[Route('/programmation/film/{id}', name: 'app_programmation_film', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function film(int $id, FilmRepository $filmRepository): Response
    {
        $film = $filmRepository->findInCurrentProgramById($id);
        if (!$film instanceof Film) {
            throw $this->createNotFoundException('Ce film n’est pas actuellement programmé.');
        }

        $weekdays = [1 => 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];
        $months = [1 => 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
        $screenings = [];
        $now = new DateTimeImmutable();

        foreach ($film->getSeances() as $seance) {
            $dateTime = $seance->getDateTime();
            if (null === $dateTime || $dateTime < $now) {
                continue;
            }

            $screenings[] = [
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

        return $this->render('programmation/film.html.twig', [
            'film' => $film,
            'screenings' => $screenings,
        ]);
    }
}
