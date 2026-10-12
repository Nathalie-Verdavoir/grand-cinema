<?php

namespace App\Controller;

use App\Entity\Film;
use App\Repository\FilmRepository;
use App\Service\ScreeningFormatter;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ProgrammationController extends AbstractController
{
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
}
