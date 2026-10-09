<?php

namespace App\Controller\Admin;

use App\Entity\Film;
use App\Entity\Salle;
use App\Entity\Seance;
use App\Repository\FilmRepository;
use App\Repository\SalleRepository;
use App\Repository\SeanceRepository;
use DateTimeImmutable;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class SeancePlanningController extends AbstractController
{
    public function __construct(
        private readonly FilmRepository $filmRepository,
        private readonly SalleRepository $salleRepository,
        private readonly SeanceRepository $seanceRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('/admin/api/seances', name: 'admin_api_seance_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('admin_create_seance', (string) $request->headers->get('X-CSRF-Token')))) {
            return $this->json(['error' => 'Votre session a expiré. Rechargez la page puis réessayez.'], 403);
        }

        $payload = $request->toArray();
        $filmId = filter_var($payload['filmId'] ?? null, FILTER_VALIDATE_INT);
        $roomId = filter_var($payload['roomId'] ?? null, FILTER_VALIDATE_INT);
        $date = $payload['date'] ?? null;
        $time = $payload['time'] ?? null;

        if (false === $filmId || $filmId < 1 || false === $roomId || $roomId < 1 || !is_string($date) || !is_string($time)) {
            return $this->json(['error' => 'Les informations de la séance sont incomplètes.'], 400);
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^(?:[01]\d|2[0-3]):(?:00|30)$/', $time)) {
            return $this->json(['error' => 'La date ou le créneau horaire est invalide.'], 400);
        }

        $startsAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$time);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$startsAt || (false !== $dateErrors && (0 !== $dateErrors['warning_count'] || 0 !== $dateErrors['error_count'])) || $startsAt->format('Y-m-d H:i') !== $date.' '.$time) {
            return $this->json(['error' => 'La date ou le créneau horaire est invalide.'], 400);
        }

        if ($startsAt <= new DateTimeImmutable('now')) {
            return $this->json(['error' => 'Impossible de programmer une séance à une date ou une heure déjà passée.'], 409);
        }

        $film = $this->filmRepository->find($filmId);
        $room = $this->salleRepository->find($roomId);
        if (!$film instanceof Film || !$room instanceof Salle) {
            return $this->json(['error' => 'Le film ou la salle sélectionné(e) n’existe plus.'], 404);
        }

        if (!$film->isProgrammable()) {
            return $this->json(['error' => 'Ce film n’est plus programmable.'], 409);
        }

        $duration = $film->getDuration();
        if (null === $duration || $duration < 1 || $duration > 600) {
            return $this->json(['error' => 'Renseignez une durée valide (entre 1 et 600 minutes) pour ce film avant de le programmer.'], 422);
        }

        $endsAt = $startsAt->modify(sprintf('+%d minutes', $duration + 20));
        $existingSeances = $this->seanceRepository->findForRoomBetween($room, $startsAt->modify('-1 day'), $endsAt);
        foreach ($existingSeances as $existingSeance) {
            $existingDateTime = $existingSeance->getDateTime();
            if (null === $existingDateTime) {
                continue;
            }

            $existingStart = DateTimeImmutable::createFromMutable($existingDateTime);
            $existingDuration = $existingSeance->getFilm()?->getDuration();

            if (null === $existingDuration) {
                return $this->json([
                    'error' => 'La durée du film d’une séance existante dans cette salle est inconnue. Renseignez-la avant d’ajouter une séance.',
                ], 409);
            }

            $existingEnd = $existingStart->modify(sprintf('+%d minutes', $existingDuration + 20));
            if ($startsAt < $existingEnd && $endsAt > $existingStart) {
                return $this->json(['error' => 'Ce créneau chevauche une autre séance ou sa période de battement de 20 minutes.'], 409);
            }
        }

        $seance = (new Seance())
            ->setFilm($film)
            ->setSalle($room)
            ->setDateTime(DateTime::createFromImmutable($startsAt));

        $this->entityManager->persist($seance);
        $this->entityManager->flush();

        return $this->json([
            'message' => sprintf('%s est programmé le %s à %s.', $film->getTitle(), $startsAt->format('d/m/Y'), $startsAt->format('H:i')),
        ], 201);
    }
}
