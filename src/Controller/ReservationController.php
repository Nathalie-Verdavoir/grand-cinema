<?php

namespace App\Controller;

use App\Entity\Seance;
use App\Entity\User;
use App\Exception\ReservationCreationException;
use App\Repository\ReservationRepository;
use App\Repository\SeanceRepository;
use App\Repository\TarifRepository;
use App\Service\ScreeningAvailability;
use App\Service\ReservationCreator;
use App\Service\ScreeningFormatter;
use App\Service\SeatingPlan;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ReservationController extends AbstractController
{
    #[Route('/reservation/seance/{id}', name: 'app_reservation', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function show(
        int $id,
        Request $request,
        SeanceRepository $seanceRepository,
        TarifRepository $tarifRepository,
        ScreeningAvailability $screeningAvailability,
        ReservationCreator $reservationCreator,
        ScreeningFormatter $screeningFormatter,
    ): Response {
        $seance = $seanceRepository->find($id);
        $dateTime = $seance instanceof Seance ? $seance->getDateTime() : null;
        if (
            !$seance instanceof Seance
            || null === $dateTime
            || !$screeningFormatter->isBookable($seance, new DateTimeImmutable())
        ) {
            throw $this->createNotFoundException('Cette séance n’est plus disponible à la réservation.');
        }

        if ($request->isMethod('POST')) {
            $csrfToken = $request->request->get('_token');
            if (!is_string($csrfToken) || !$this->isCsrfTokenValid('reservation-'.$id, $csrfToken)) {
                throw $this->createAccessDeniedException('Le formulaire de réservation a expiré. Veuillez réessayer.');
            }

            $user = $this->getUser();
            if (!$user instanceof User) {
                $this->addFlash('danger', 'Connectez-vous pour confirmer votre réservation.');

                return $this->redirectToRoute('login', [
                    '_target_path' => $this->generateUrl('app_reservation', ['id' => $id]),
                ]);
            }

            if ([] === $request->request->all('seats')) {
                $this->addFlash('danger', 'Choisissez vos places sur le plan de salle.');

                return $this->redirectToRoute('app_reservation', ['id' => $id]);
            }

            try {
                $reservation = $reservationCreator->create(
                    $seance,
                    $user,
                    $request->request->all('tarifs'),
                    array_values($request->request->all('seats'))
                );
            } catch (ReservationCreationException $exception) {
                $this->addFlash('danger', $exception->getMessage());

                return $this->redirectToRoute('app_reservation', ['id' => $id]);
            }

            $this->addFlash('success', sprintf(
                'Votre réservation %s est enregistrée. Vous pouvez procéder au paiement.',
                (string) $reservation->getReference()
            ));

            return $this->redirectToRoute('app_reservation_paiement', [
                'reference' => (string) $reservation->getReference(),
            ]);
        }

        $response = $this->render('reservation/show.html.twig', [
            'seance' => $seance,
            'availableSeats' => $screeningAvailability->calculate($seance)['availableSeats'],
            'tarifs' => $tarifRepository->findBy([], ['price' => 'DESC', 'name' => 'ASC']),
        ]);
        $response->setSharedMaxAge(10);
        $response->setVary('Cookie');

        return $response;
    }

    #[Route('/reservation/seance/{id}/places', name: 'app_reservation_places', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function seats(
        int $id,
        Request $request,
        SeanceRepository $seanceRepository,
        TarifRepository $tarifRepository,
        ReservationRepository $reservationRepository,
        ScreeningAvailability $screeningAvailability,
        ScreeningFormatter $screeningFormatter,
        SeatingPlan $seatingPlan,
    ): Response {
        $seance = $seanceRepository->find($id);
        $dateTime = $seance instanceof Seance ? $seance->getDateTime() : null;
        if (
            !$seance instanceof Seance
            || null === $dateTime
            || !$screeningFormatter->isBookable($seance, new DateTimeImmutable())
        ) {
            throw $this->createNotFoundException('Cette séance n’est plus disponible à la réservation.');
        }

        if (!$request->isMethod('POST')) {
            return $this->redirectToRoute('app_reservation', ['id' => $id]);
        }

        $csrfToken = $request->request->get('_token');
        if (!is_string($csrfToken) || !$this->isCsrfTokenValid('reservation-'.$id, $csrfToken)) {
            throw $this->createAccessDeniedException('Le formulaire de réservation a expiré. Veuillez réessayer.');
        }

        $tarifs = [];
        $quantities = [];
        $totalSeats = 0;
        foreach ($tarifRepository->findAll() as $tarif) {
            $submitted = $request->request->all('tarifs')[$tarif->getId()] ?? 0;
            $quantity = is_scalar($submitted) ? filter_var((string) $submitted, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) : false;
            if (false === $quantity) {
                $this->addFlash('danger', 'Le nombre de places sélectionné est invalide.');

                return $this->redirectToRoute('app_reservation', ['id' => $id]);
            }
            if ($quantity > 0) {
                $tarifs[] = ['tarif' => $tarif, 'quantity' => $quantity];
                $quantities[$tarif->getId()] = $quantity;
                $totalSeats += $quantity;
            }
        }

        if (0 === $totalSeats || $totalSeats > $screeningAvailability->calculate($seance)['availableSeats']) {
            $this->addFlash('danger', 0 === $totalSeats
                ? 'Sélectionnez au moins une place pour réserver.'
                : 'Il ne reste pas assez de places pour cette sélection.');

            return $this->redirectToRoute('app_reservation', ['id' => $id]);
        }

        $totalPrice = 0.0;
        foreach ($tarifs as $selection) {
            $totalPrice += (float) $selection['tarif']->getPrice() * $selection['quantity'];
        }

        return $this->render('reservation/seats.html.twig', [
            'seance' => $seance,
            'rows' => $seatingPlan->rows($seance->getSalle()?->getCapacity() ?? 0),
            'occupiedSeats' => $reservationRepository->findOccupiedSeatNumbersForSeance($seance),
            'quantities' => $quantities,
            'tarifs' => $tarifs,
            'totalSeats' => $totalSeats,
            'totalPrice' => $totalPrice,
        ]);
    }
}
