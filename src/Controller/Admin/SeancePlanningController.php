<?php

namespace App\Controller\Admin;

use App\Entity\Film;
use App\Entity\Salle;
use App\Entity\Seance;
use App\Enum\SeanceStatus;
use App\Repository\FilmRepository;
use App\Repository\SalleRepository;
use App\Repository\SeanceRepository;
use App\Service\WeeklySeanceDraftGenerator;
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
use Symfony\Component\Workflow\WorkflowInterface;

#[IsGranted('ROLE_ADMIN')]
class SeancePlanningController extends AbstractController
{
    public function __construct(
        private readonly FilmRepository $filmRepository,
        private readonly SalleRepository $salleRepository,
        private readonly SeanceRepository $seanceRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly WorkflowInterface $seancePublishingStateMachine,
        private readonly WeeklySeanceDraftGenerator $weeklySeanceDraftGenerator,
    ) {
    }

    #[Route('/admin/seances/generate-week', name: 'admin_seance_generate_week', methods: ['POST'])]
    public function generateWeek(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        if (!$this->isCsrfTokenValid('admin_generate_week', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Votre session a expiré. Rechargez la page puis réessayez.');

            return $this->redirectToRoute('admin_seance_index');
        }

        $week = $request->request->get('week');
        $weekStart = is_string($week) ? DateTimeImmutable::createFromFormat('!Y-m-d', $week) : false;
        $errors = DateTimeImmutable::getLastErrors();
        if (false === $weekStart || (false !== $errors && (0 !== $errors['warning_count'] || 0 !== $errors['error_count'])) || $weekStart->format('Y-m-d') !== $week) {
            $this->addFlash('danger', 'La semaine sélectionnée est invalide.');

            return $this->redirectToRoute('admin_seance_index');
        }

        $weekStart = $weekStart->modify('monday this week');
        $roomId = filter_var($request->request->get('salle'), FILTER_VALIDATE_INT);
        $room = false !== $roomId ? $this->salleRepository->find($roomId) : null;
        if (!$room instanceof Salle) {
            $this->addFlash('danger', 'La salle sélectionnée est invalide.');

            return $this->redirectToRoute('admin_seance_index', ['week' => $weekStart->format('Y-m-d')]);
        }

        $result = $this->weeklySeanceDraftGenerator->generate($weekStart, $room);
        $this->addFlash('success', sprintf('%d séance(s) brouillon(s) générée(s) pour la semaine du %s.%s', $result['created'], $weekStart->format('d/m/Y'), $result['skipped'] > 0 ? sprintf(' %d film(s) n’ont pas trouvé de créneau disponible.', $result['skipped']) : ''));

        return $this->redirectToRoute('admin_seance_index', [
            'week' => $weekStart->format('Y-m-d'),
            'salle' => $room->getId(),
        ]);
    }

    #[Route('/admin/seances/generate-day', name: 'admin_seance_generate_day', methods: ['POST'])]
    public function generateDay(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        if (!$this->isCsrfTokenValid('admin_generate_day', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Votre session a expiré. Rechargez la page puis réessayez.');

            return $this->redirectToDailyView($request);
        }

        $day = $this->parseDate($request->request->get('day'));
        if (null === $day) {
            $this->addFlash('danger', 'Le jour sélectionné est invalide.');

            return $this->redirectToDailyView($request);
        }

        $result = $this->weeklySeanceDraftGenerator->generateDay($day);
        $this->addFlash('success', sprintf(
            '%d séance(s) brouillon(s) générée(s) pour le %s.%s',
            $result['created'],
            $day->format('d/m/Y'),
            $result['skipped'] > 0 ? sprintf(' %d film(s) n’ont pas trouvé de créneau disponible.', $result['skipped']) : ''
        ));

        return $this->redirectToDailyView($request, $day);
    }

    #[Route('/admin/seances/publish-day', name: 'admin_seance_publish_day', methods: ['POST'])]
    public function publishDay(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        if (!$this->isCsrfTokenValid('admin_publish_day', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Votre session a expiré. Rechargez la page puis réessayez.');

            return $this->redirectToDailyView($request);
        }

        $day = $this->parseDate($request->request->get('day'));
        if (null === $day) {
            $this->addFlash('danger', 'Le jour sélectionné est invalide.');

            return $this->redirectToDailyView($request);
        }

        $published = 0;
        foreach ($this->seanceRepository->findBetween($day, $day->modify('+1 day')) as $seance) {
            if ($this->seancePublishingStateMachine->can($seance, 'publish')) {
                $this->seancePublishingStateMachine->apply($seance, 'publish');
                ++$published;
            }
        }
        if ($published > 0) {
            $this->entityManager->flush();
        }

        $this->addFlash('success', sprintf('%d séance(s) brouillon(s) publiée(s) pour le %s.', $published, $day->format('d/m/Y')));

        return $this->redirectToDailyView($request, $day);
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

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^(?:[01]\d|2[0-3]):(?:00|15|30|45)$/', $time)) {
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
            ->setDateTime(DateTime::createFromImmutable($startsAt))
            ->setStatus(SeanceStatus::DRAFT);

        $this->entityManager->persist($seance);
        $this->entityManager->flush();

        return $this->json([
            'message' => sprintf('%s est programmé le %s à %s.', $film->getTitle(), $startsAt->format('d/m/Y'), $startsAt->format('H:i')),
        ], 201);
    }

    #[Route('/admin/api/seances/{id}', name: 'admin_api_seance_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('admin_update_seance', (string) $request->headers->get('X-CSRF-Token')))) {
            return $this->json(['error' => 'Votre session a expiré. Rechargez la page puis réessayez.'], 403);
        }

        $seance = $this->seanceRepository->find($id);
        if (!$seance instanceof Seance) {
            return $this->json(['error' => 'Cette séance n’existe plus. Rechargez le planning.'], 404);
        }

        $currentDateTime = $seance->getDateTime();
        if (null === $currentDateTime || DateTimeImmutable::createFromMutable($currentDateTime) <= new DateTimeImmutable('now')) {
            return $this->json(['error' => 'Une séance déjà commencée ne peut pas être déplacée.'], 409);
        }
        if (!$seance->getReservations()->isEmpty()) {
            return $this->json(['error' => 'Cette séance ne peut pas être déplacée car elle possède des réservations.'], 409);
        }

        $payload = $request->toArray();
        $roomId = filter_var($payload['roomId'] ?? null, FILTER_VALIDATE_INT);
        $date = $payload['date'] ?? null;
        $time = $payload['time'] ?? null;
        if (false === $roomId || $roomId < 1 || !is_string($date) || !is_string($time)) {
            return $this->json(['error' => 'Les informations de la séance sont incomplètes.'], 400);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^(?:[01]\d|2[0-3]):(?:00|15|30|45)$/', $time)) {
            return $this->json(['error' => 'La date ou le créneau horaire est invalide.'], 400);
        }

        $startsAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$time);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$startsAt || (false !== $dateErrors && (0 !== $dateErrors['warning_count'] || 0 !== $dateErrors['error_count'])) || $startsAt->format('Y-m-d H:i') !== $date.' '.$time) {
            return $this->json(['error' => 'La date ou le créneau horaire est invalide.'], 400);
        }
        if ($startsAt <= new DateTimeImmutable('now')) {
            return $this->json(['error' => 'Impossible de déplacer une séance vers une date ou une heure déjà passée.'], 409);
        }

        $room = $this->salleRepository->find($roomId);
        $duration = $seance->getFilm()?->getDuration();
        if (!$room instanceof Salle || null === $duration || $duration < 1 || $duration > 600) {
            return $this->json(['error' => 'La salle ou la durée du film est invalide.'], 422);
        }

        $endsAt = $startsAt->modify(sprintf('+%d minutes', $duration + 20));
        foreach ($this->seanceRepository->findForRoomBetween($room, $startsAt->modify('-1 day'), $endsAt) as $existingSeance) {
            if ($existingSeance === $seance) {
                continue;
            }
            $existingStart = $existingSeance->getDateTime();
            $existingDuration = $existingSeance->getFilm()?->getDuration();
            if (null === $existingStart || null === $existingDuration) {
                continue;
            }
            $existingStartAt = DateTimeImmutable::createFromMutable($existingStart);
            $existingEndAt = $existingStartAt->modify(sprintf('+%d minutes', $existingDuration + 20));
            if ($startsAt < $existingEndAt && $endsAt > $existingStartAt) {
                return $this->json(['error' => 'Ce créneau chevauche une autre séance ou sa période de battement de 20 minutes.'], 409);
            }
        }

        $seance->setSalle($room)->setDateTime(DateTime::createFromImmutable($startsAt));
        $this->entityManager->flush();

        return $this->json([
            'message' => sprintf('%s est déplacé le %s à %s.', $seance->getFilm()?->getTitle(), $startsAt->format('d/m/Y'), $startsAt->format('H:i')),
        ]);
    }

    #[Route('/admin/api/seances/{id}/publish', name: 'admin_api_seance_publish', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function publish(int $id, Request $request): JsonResponse
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('admin_publish_seance', (string) $request->headers->get('X-CSRF-Token')))) {
            return $this->json(['error' => 'Votre session a expiré. Rechargez la page puis réessayez.'], 403);
        }

        $seance = $this->seanceRepository->find($id);
        if (!$seance instanceof Seance) {
            return $this->json(['error' => 'Cette séance n’existe plus. Rechargez le planning.'], 404);
        }

        if (!$this->seancePublishingStateMachine->can($seance, 'publish')) {
            return $this->json(['error' => 'Cette séance est déjà publiée ou ne peut plus être publiée.'], 409);
        }

        $this->seancePublishingStateMachine->apply($seance, 'publish');
        $this->entityManager->flush();

        return $this->json(['message' => 'La séance est maintenant visible et réservable.']);
    }

    #[Route('/admin/api/seances/{id}', name: 'admin_api_seance_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('admin_delete_seance', (string) $request->headers->get('X-CSRF-Token')))) {
            return $this->json(['error' => 'Votre session a expiré. Rechargez la page puis réessayez.'], 403);
        }

        $seance = $this->seanceRepository->find($id);
        if (!$seance instanceof Seance) {
            return $this->json(['error' => 'Cette séance n’existe plus. Rechargez le planning.'], 404);
        }

        $dateTime = $seance->getDateTime();
        if (null === $dateTime || DateTimeImmutable::createFromMutable($dateTime) <= new DateTimeImmutable('now')) {
            return $this->json(['error' => 'Une séance déjà commencée ne peut pas être supprimée.'], 409);
        }

        if (!$seance->getReservations()->isEmpty()) {
            return $this->json(['error' => 'Cette séance ne peut pas être supprimée car elle possède des réservations.'], 409);
        }

        $this->entityManager->remove($seance);
        $this->entityManager->flush();

        return $this->json(['message' => 'La séance a été supprimée.']);
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $date && (false === $errors || (0 === $errors['warning_count'] && 0 === $errors['error_count'])) && $date->format('Y-m-d') === $value
            ? $date
            : null;
    }

    private function redirectToDailyView(Request $request, ?DateTimeImmutable $day = null): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $day ??= $this->parseDate($request->request->get('day')) ?? new DateTimeImmutable('today');
        $room = filter_var($request->request->get('salle'), FILTER_VALIDATE_INT);

        return $this->redirectToRoute('admin_seance_index', [
            'view' => 'day',
            'day' => $day->format('Y-m-d'),
            'salle' => false !== $room ? $room : null,
        ]);
    }
}
