<?php

namespace App\Controller\Admin;

use App\Enum\ReservationStatus;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[AdminRoute(path: '/reservations-guichet', name: 'reservations_guichet')]
final class CounterReservationController extends AbstractController
{
    #[AdminRoute(path: '', name: 'index', options: ['methods' => ['GET']])]
    public function index(ReservationRepository $reservationRepository): Response
    {
        return $this->render('admin/counter_reservations.html.twig', [
            'reservations' => $reservationRepository->findCounterReservations(),
            'statuses' => ReservationStatus::cases(),
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

        $reservation->setStatus($status);
        $entityManager->flush();
        $this->addFlash('success', sprintf(
            'Le statut de la réservation %s a été modifié.',
            (string) $reservation->getReference()
        ));

        return $this->redirectToRoute('admin_reservations_guichet_index');
    }
}
