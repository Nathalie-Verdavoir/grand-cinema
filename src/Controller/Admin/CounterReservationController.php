<?php

namespace App\Controller\Admin;

use App\Enum\ReservationStatus;
use App\Exception\ReservationCreationException;
use App\Repository\ReservationRepository;
use App\Service\ScreeningAvailability;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[AdminRoute(path: '/reservations-guichet', name: 'reservations_guichet')]
final class CounterReservationController extends AbstractController
{
    #[AdminRoute(path: '', name: 'index', options: ['methods' => ['GET']])]
    public function index(Request $request, ReservationRepository $reservationRepository): Response
    {
        $pageSize = 25;
        $page = max(1, $request->query->getInt('page', 1));
        $reservations = $reservationRepository->findCounterReservations($page, $pageSize + 1);
        $hasNextPage = count($reservations) > $pageSize;
        if ($hasNextPage) {
            array_pop($reservations);
        }

        return $this->render('admin/counter_reservations.html.twig', [
            'reservations' => $reservations,
            'statuses' => ReservationStatus::cases(),
            'currentPage' => $page,
            'hasNextPage' => $hasNextPage,
        ]);
    }

    #[AdminRoute(
        path: '/{id}/statut',
        name: 'update_status',
        options: ['requirements' => ['id' => '\d+'], 'methods' => ['POST']]
    )]
    public function updateStatus(
        int $id,
        Request $request,
        ReservationRepository $reservationRepository,
        ScreeningAvailability $screeningAvailability,
        EntityManagerInterface $entityManager,
    ): Response {
        $reservation = $reservationRepository->findCounterReservation($id);
        if (null === $reservation) {
            throw $this->createNotFoundException('Cette réservation guichet est introuvable.');
        }

        $csrfToken = $request->request->get('_token');
        if (!is_string($csrfToken) || !$this->isCsrfTokenValid('counter-reservation-status-'.$id, $csrfToken)) {
            throw $this->createAccessDeniedException('Le formulaire a expiré. Veuillez réessayer.');
        }

        $statusValue = $request->request->get('status');
        $status = is_string($statusValue) ? ReservationStatus::tryFrom($statusValue) : null;
        if (null === $status) {
            $this->addFlash('danger', 'Le statut sélectionné est invalide.');

            return $this->redirectToRoute('admin_reservations_guichet_index');
        }

        try {
            $entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use (
                $reservation,
                $status,
                $reservationRepository,
                $screeningAvailability
            ): void {
                $seance = $reservation->getSeance();
                if (null === $seance) {
                    throw new LogicException('La réservation guichet ne possède pas de séance.');
                }

                $entityManager->lock($seance, LockMode::PESSIMISTIC_WRITE);
                $entityManager->refresh($reservation);

                if (!$reservation->getStatus()?->reservesSeats() && $status->reservesSeats()) {
                    $reservedSeats = $reservationRepository->countReservedSeatsForSeance($seance);
                    $availableSeats = $screeningAvailability->calculate($seance, $reservedSeats)['availableSeats'];

                    if (($reservation->getNbPlaces() ?? 0) > $availableSeats) {
                        throw new ReservationCreationException('Il ne reste pas assez de places pour cette sélection.');
                    }
                }

                $reservation->setStatus($status);
            });
        } catch (ReservationCreationException $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute('admin_reservations_guichet_index');
        }

        $this->addFlash('success', sprintf(
            'Le statut de la réservation %s a été modifié.',
            (string) $reservation->getReference()
        ));

        return $this->redirectToRoute('admin_reservations_guichet_index');
    }
}
