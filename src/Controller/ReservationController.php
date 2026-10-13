<?php

namespace App\Controller;

use App\Entity\Seance;
use App\Entity\User;
use App\Enum\SeanceStatus;
use App\Exception\ReservationCreationException;
use App\Repository\SeanceRepository;
use App\Repository\TarifRepository;
use App\Service\ScreeningAvailability;
use App\Service\ReservationCreator;
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
    ): Response {
        $seance = $seanceRepository->find($id);
        $dateTime = $seance instanceof Seance ? $seance->getDateTime() : null;
        if (
            !$seance instanceof Seance
            || SeanceStatus::PUBLISHED !== $seance->getStatus()
            || null === $dateTime
            || DateTimeImmutable::createFromInterface($dateTime) < new DateTimeImmutable()
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

            try {
                $reservation = $reservationCreator->create(
                    $seance,
                    $user,
                    $request->request->all('tarifs')
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

        return $this->render('reservation/show.html.twig', [
            'seance' => $seance,
            'availableSeats' => $screeningAvailability->calculate($seance)['availableSeats'],
            'tarifs' => $tarifRepository->findBy([], ['price' => 'DESC', 'name' => 'ASC']),
        ]);
    }
}
