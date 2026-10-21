<?php

namespace App\Service;

use App\Entity\Billet;
use App\Entity\Reservation;
use App\Entity\Seance;
use App\Entity\Tarif;
use App\Entity\User;
use App\Enum\ReservationStatus;
use App\Enum\ReservationChannel;
use App\Enum\SeanceStatus;
use App\Exception\ReservationCreationException;
use App\Repository\ReservationRepository;
use App\Repository\TarifRepository;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

final class ReservationCreator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TarifRepository $tarifRepository,
        private readonly ScreeningAvailability $screeningAvailability,
        private readonly ReservationRepository $reservationRepository,
        private readonly AdminKpiAggregateUpdaterInterface $aggregateUpdater,
    ) {
    }

    /**
     * @param array<string|int, mixed> $submittedQuantities
     */
    public function create(Seance $seance, User $user, array $submittedQuantities): Reservation
    {
        return $this->createWithStatus(
            $seance,
            $user,
            $submittedQuantities,
            ReservationStatus::PENDING,
            ReservationChannel::ONLINE
        );
    }

    /**
     * @param array<string|int, mixed> $submittedQuantities
     */
    public function createAtCounter(Seance $seance, User $user, array $submittedQuantities): Reservation
    {
        return $this->createWithStatus(
            $seance,
            $user,
            $submittedQuantities,
            ReservationStatus::PAID_AT_COUNTER,
            ReservationChannel::COUNTER
        );
    }

    /**
     * @param array<string|int, mixed> $submittedQuantities
     */
    private function createWithStatus(
        Seance $seance,
        User $user,
        array $submittedQuantities,
        ReservationStatus $status,
        ReservationChannel $channel,
    ): Reservation
    {
        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($seance, $user, $submittedQuantities, $status, $channel): Reservation {
            $entityManager->lock($seance, LockMode::PESSIMISTIC_WRITE);
            $dateTime = $seance->getDateTime();
            if (
                SeanceStatus::PUBLISHED !== $seance->getStatus()
                || null === $dateTime
                || DateTimeImmutable::createFromInterface($dateTime) < new DateTimeImmutable()
            ) {
                throw new ReservationCreationException('Cette séance n’est plus disponible à la réservation.');
            }

            $tarifsById = [];
            foreach ($this->tarifRepository->findAll() as $tarif) {
                if (null !== $tarif->getId()) {
                    $tarifsById[$tarif->getId()] = $tarif;
                }
            }

            $selectedTarifs = [];
            $totalSeats = 0;
            $totalPriceCents = 0;
            foreach ($submittedQuantities as $tarifId => $rawQuantity) {
                if ((!is_string($tarifId) && !is_int($tarifId)) || !ctype_digit((string) $tarifId)) {
                    throw new ReservationCreationException('La sélection des tarifs est invalide.');
                }

                $tarif = $tarifsById[(int) $tarifId] ?? null;
                if (!$tarif instanceof Tarif || (!is_string($rawQuantity) && !is_int($rawQuantity))) {
                    throw new ReservationCreationException('La sélection des tarifs est invalide.');
                }

                $quantity = '' === (string) $rawQuantity
                    ? 0
                    : filter_var((string) $rawQuantity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if (false === $quantity) {
                    throw new ReservationCreationException('Le nombre de places sélectionné est invalide.');
                }
                if (0 === $quantity) {
                    continue;
                }

                $selectedTarifs[] = ['tarif' => $tarif, 'quantity' => $quantity];
                $totalSeats += $quantity;
                $totalPriceCents += $this->priceInCents($tarif) * $quantity;
            }

            if (0 === $totalSeats) {
                throw new ReservationCreationException('Sélectionnez au moins une place pour réserver.');
            }

            $reservedSeats = $this->reservationRepository->countReservedSeatsForSeance($seance);
            if ($totalSeats > $this->screeningAvailability->calculate($seance, $reservedSeats)['availableSeats']) {
                throw new ReservationCreationException('Il ne reste pas assez de places pour cette sélection.');
            }

            $reservation = (new Reservation())
                ->setReference(new Ulid())
                ->setUser($user)
                ->setReservedAt(new DateTimeImmutable())
                ->setTotalPrice($this->formatPrice($totalPriceCents))
                ->setNbPlaces($totalSeats)
                ->setStatus($status)
                ->setChannel($channel)
                ->setSeance($seance);
            $entityManager->persist($reservation);

            foreach ($selectedTarifs as $selection) {
                /** @var Tarif $tarif */
                $tarif = $selection['tarif'];
                for ($index = 0; $index < $selection['quantity']; ++$index) {
                    $billet = (new Billet())
                        ->setTarif($tarif)
                        ->setPriceApplied($this->formatPrice($this->priceInCents($tarif)))
                        ->setReference(new Ulid());
                    $reservation->addBillet($billet);
                    $entityManager->persist($billet);
                }
            }

            if (ReservationStatus::PAID_AT_COUNTER === $status) {
                $this->aggregateUpdater->applyStatusChange($reservation, null, $status);
            }

            return $reservation;
        });
    }

    private function priceInCents(Tarif $tarif): int
    {
        $price = $tarif->getPrice();
        if (!is_string($price) || !preg_match('/^\d+(?:\.\d{1,2})?$/D', $price)) {
            throw new \LogicException(sprintf('Le prix du tarif "%s" est invalide.', $tarif->getName() ?? 'inconnu'));
        }

        [$euros, $cents] = array_pad(explode('.', $price, 2), 2, '');

        return (int) $euros * 100 + (int) str_pad($cents, 2, '0');
    }

    private function formatPrice(int $priceInCents): string
    {
        return sprintf('%d.%02d', intdiv($priceInCents, 100), $priceInCents % 100);
    }
}
