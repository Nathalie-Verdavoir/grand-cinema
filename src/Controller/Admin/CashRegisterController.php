<?php

namespace App\Controller\Admin;

use App\Entity\Reservation;
use App\Entity\Seance;
use App\Entity\User;
use App\Enum\ReservationStatus;
use App\Enum\SeanceStatus;
use App\Exception\ReservationCreationException;
use App\Repository\ReservationRepository;
use App\Repository\SeanceRepository;
use App\Repository\TarifRepository;
use App\Service\ReservationCreator;
use App\Service\ScreeningAvailability;
use App\Service\TicketPdfGenerator;
use DateTimeImmutable;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

#[IsGranted('ROLE_ADMIN')]
#[AdminRoute(path: '/caisse', name: 'caisse')]
final class CashRegisterController extends AbstractController
{
    #[AdminRoute(path: '', name: 'index', options: ['methods' => ['GET']])]
    public function index(
        Request $request,
        SeanceRepository $seanceRepository,
        TarifRepository $tarifRepository,
        ScreeningAvailability $screeningAvailability,
    ): Response {
        $screenings = $seanceRepository->findPublishedFrom(new DateTimeImmutable());
        $films = [];
        foreach ($screenings as $screening) {
            $film = $screening->getFilm();
            if (null !== $film->getId()) {
                $films[$film->getId()] = $film;
            }
        }

        $selectedFilmId = null;
        $requestedFilmId = $request->query->get('film');
        if (null !== $requestedFilmId && '' !== $requestedFilmId) {
            $filmId = filter_var($requestedFilmId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (false === $filmId) {
                throw new BadRequestHttpException('Le film sélectionné est invalide.');
            }
            if (!isset($films[$filmId])) {
                throw $this->createNotFoundException('Ce film n’a aucune séance disponible à la vente.');
            }

            $selectedFilmId = $filmId;
        }

        $selectedId = $request->query->get('seance');
        $selectedScreening = null;
        if (null !== $selectedId && '' !== $selectedId) {
            $selectedScreening = $this->findSellableScreening($selectedId, $seanceRepository);

            $screeningFilmId = $selectedScreening->getFilm()->getId();
            if (null !== $selectedFilmId && $selectedFilmId !== $screeningFilmId) {
                throw new BadRequestHttpException('La séance sélectionnée ne correspond pas au film choisi.');
            }
            $selectedFilmId = $screeningFilmId;
        }

        $filmScreenings = null === $selectedFilmId ? [] : array_values(array_filter(
            $screenings,
            static fn (Seance $screening): bool => $screening->getFilm()->getId() === $selectedFilmId,
        ));

        return $this->render('admin/cash_register.html.twig', [
            'films' => array_values($films),
            'filmScreenings' => $filmScreenings,
            'selectedFilmId' => $selectedFilmId,
            'selectedScreening' => $selectedScreening,
            'tarifs' => $tarifRepository->findBy([], ['price' => 'DESC', 'name' => 'ASC']),
            'availability' => $selectedScreening instanceof Seance ? $screeningAvailability->calculate($selectedScreening) : null,
        ]);
    }

    #[AdminRoute(path: '/details-seance', name: 'sale_details', options: ['methods' => ['GET']])]
    public function saleDetails(
        Request $request,
        SeanceRepository $seanceRepository,
        TarifRepository $tarifRepository,
        ScreeningAvailability $screeningAvailability,
    ): JsonResponse {
        $filmId = filter_var($request->query->get('film'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $filmId) {
            throw new BadRequestHttpException('Le film sélectionné est invalide.');
        }

        $screening = $this->findSellableScreening($request->query->get('seance'), $seanceRepository);
        if ($screening->getFilm()->getId() !== $filmId) {
            throw new BadRequestHttpException('La séance sélectionnée ne correspond pas au film choisi.');
        }

        $html = $this->renderView('admin/_cash_sale_details.html.twig', [
            'selectedScreening' => $screening,
            'tarifs' => $tarifRepository->findBy([], ['price' => 'DESC', 'name' => 'ASC']),
            'availability' => $screeningAvailability->calculate($screening),
        ]);

        return $this->json(['html' => $html]);
    }

    #[AdminRoute(path: '/seances', name: 'screenings', options: ['methods' => ['GET']])]
    public function screenings(Request $request, SeanceRepository $seanceRepository): JsonResponse
    {
        $filmId = filter_var($request->query->get('film'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $filmId) {
            throw new BadRequestHttpException('Le film sélectionné est invalide.');
        }

        $screenings = $seanceRepository->findPublishedForFilmFrom($filmId, new DateTimeImmutable());
        $options = array_map(static function (Seance $screening): array {
            $id = $screening->getId();
            $dateTime = $screening->getDateTime();
            $room = $screening->getSalle();
            if (null === $id || null === $dateTime || null === $room) {
                throw new \LogicException('Une séance publiée doit avoir un identifiant, une date et une salle.');
            }

            return [
                'id' => $id,
                'label' => sprintf('%s — %s', $dateTime->format('d/m/Y H:i'), $room->getName()),
            ];
        }, $screenings);

        return $this->json($options);
    }

    private function findSellableScreening(mixed $screeningId, SeanceRepository $seanceRepository): Seance
    {
        $id = filter_var($screeningId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $id) {
            throw new BadRequestHttpException('La séance sélectionnée est invalide.');
        }

        $screening = $seanceRepository->find($id);
        if (
            !$screening instanceof Seance
            || SeanceStatus::PUBLISHED !== $screening->getStatus()
            || !$screening->getDateTime()
            || $screening->getDateTime() < new \DateTime()
        ) {
            throw $this->createNotFoundException('Cette séance n’est plus disponible à la vente.');
        }

        return $screening;
    }

    #[AdminRoute(path: '/vente', name: 'sell', options: ['methods' => ['POST']])]
    public function sell(
        Request $request,
        SeanceRepository $seanceRepository,
        ReservationCreator $reservationCreator,
        TicketPdfGenerator $ticketPdfGenerator,
    ): Response {
        $seanceId = filter_var($request->request->get('seance'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $seanceId) {
            throw new BadRequestHttpException('La séance sélectionnée est invalide.');
        }
        $screening = $seanceRepository->find($seanceId);
        if (!$screening instanceof Seance) {
            throw $this->createNotFoundException('Cette séance n’existe pas.');
        }

        $csrfToken = $request->request->get('_token');
        if (!is_string($csrfToken) || !$this->isCsrfTokenValid('admin-cash-sale-'.$seanceId, $csrfToken)) {
            throw $this->createAccessDeniedException('Le formulaire de vente a expiré. Veuillez réessayer.');
        }

        $admin = $this->getUser();
        if (!$admin instanceof User) {
            throw $this->createAccessDeniedException();
        }

        try {
            $reservation = $reservationCreator->createAtCounter($screening, $admin, $request->request->all('quantities'));
        } catch (ReservationCreationException $exception) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute('admin_caisse_index', ['seance' => $seanceId]);
        }

        if ($request->isXmlHttpRequest()) {
            $reference = (string) $reservation->getReference();

            return $this->createTicketPdfResponse($ticketPdfGenerator->generate($reservation), $reference);
        }

        return $this->redirectToRoute('admin_caisse_tickets', ['reference' => (string) $reservation->getReference()]);
    }

    #[AdminRoute(
        path: '/{reference}/billets',
        name: 'tickets',
        options: ['requirements' => ['reference' => '[0-9A-HJKMNP-TV-Z]{26}'], 'methods' => ['GET']]
    )]
    public function tickets(
        string $reference,
        ReservationRepository $reservationRepository,
        TicketPdfGenerator $ticketPdfGenerator,
    ): Response {
        $reservation = $reservationRepository->findOneBy(['reference' => Ulid::fromString($reference)]);
        if (!$reservation instanceof Reservation || ReservationStatus::PAID_AT_COUNTER !== $reservation->getStatus()) {
            throw $this->createNotFoundException('Ces billets de caisse ne sont pas disponibles.');
        }

        return $this->createTicketPdfResponse($ticketPdfGenerator->generate($reservation), $reference);
    }

    private function createTicketPdfResponse(string $pdf, string $reference): Response
    {
        $response = new Response($pdf);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            sprintf('billets-caisse-%s.pdf', $reference)
        ));

        return $response;
    }
}
