<?php

namespace App\Service;

use App\Entity\Billet;
use App\Entity\Reservation;
use App\Entity\Seance;
use App\Entity\Tarif;
use App\Entity\User;
use App\Enum\ReservationChannel;
use App\Enum\ReservationStatus;
use App\Repository\SeanceRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

final class ReservationFixturesGenerator
{
    private const ONLINE_SHARE_PERCENT = 30;
    private const RECENT_SALES_DAYS = 21;
    private const MAX_SALE_LEAD_DAYS = 14;
    private const TICKET_BATCH_SIZE = 2000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SeanceRepository $seanceRepository,
    ) {
    }

    /**
     * @param list<User> $administrators
     * @param list<User> $customers
     * @param array<string, Tarif> $tariffsByCode
     *
     * @return array{reservations: int, tickets: int}
     */
    public function generate(
        DateTimeImmutable $today,
        array $administrators,
        array $customers,
        array $tariffsByCode,
    ): array {
        if ([] === $administrators || [] === $customers) {
            throw new \InvalidArgumentException('Les fixtures doivent fournir des comptes administrateur et client.');
        }

        foreach (['PLEIN', 'REDUIT', 'ETUDIANT', 'ENFANT', 'SENIOR', 'CHOMEUR'] as $code) {
            if (!isset($tariffsByCode[$code])) {
                throw new \InvalidArgumentException(sprintf('Le tarif "%s" est absent des fixtures.', $code));
            }
        }

        $today = $today->setTime(0, 0);
        $from = $today->modify('-1 year');
        $recentSalesFrom = $today->modify(sprintf('-%d days', self::RECENT_SALES_DAYS));
        $reservationsCreated = 0;
        $ticketsCreated = 0;
        $pendingTickets = 0;

        for ($date = $from; $date < $today; $date = $date->modify('+1 day')) {
            $screenings = $this->seanceRepository->findPublishedBetween($date, $date->modify('+1 day'));
            foreach ($screenings as $screening) {
                $screeningDateTime = $screening->getDateTime();
                $room = $screening->getSalle();
                $capacity = $room?->getCapacity();
                if (null === $screeningDateTime || null === $capacity || $capacity < 1) {
                    throw new \LogicException('Une séance fixture publiée doit avoir une date et une capacité de salle valides.');
                }

                $occupancyPercent = random_int(15, 45);
                $ticketCount = max(1, (int) round($capacity * $occupancyPercent / 100));
                $seatNumbers = range(1, $capacity);
                shuffle($seatNumbers);
                $seatIndex = 0;
                $screeningDateTime = DateTimeImmutable::createFromInterface($screeningDateTime);

                while ($ticketCount > 0) {
                    $groupSize = min($ticketCount, $this->randomGroupSize());
                    $reservedAt = $this->saleDate($screeningDateTime, $from, $recentSalesFrom);
                    $isOnline = $reservedAt >= $recentSalesFrom
                        && random_int(1, 100) <= self::ONLINE_SHARE_PERCENT;
                    $channel = $isOnline ? ReservationChannel::ONLINE : ReservationChannel::COUNTER;
                    $status = $isOnline ? ReservationStatus::PAID : ReservationStatus::PAID_AT_COUNTER;
                    $user = $isOnline
                        ? $customers[random_int(0, count($customers) - 1)]
                        : $administrators[random_int(0, count($administrators) - 1)];
                    $managedUser = $this->managedUser($user);
                    $managedScreening = $this->managedScreening($screening);
                    $reservationTotalCents = 0;
                    $reservationTickets = [];

                    for ($index = 0; $index < $groupSize; ++$index) {
                        $tariff = $this->randomTariff($tariffsByCode);
                        $priceCents = $this->priceInCents($tariff);
                        $reservationTotalCents += $priceCents;
                        $reservationTickets[] = [
                            'tariff' => $this->managedTariff($tariff),
                            'price' => $this->formatPrice($priceCents),
                            'seatNumber' => $this->formatSeatNumber($seatNumbers[$seatIndex]),
                        ];
                        ++$seatIndex;
                    }

                    $reservation = (new Reservation())
                        ->setReference(new Ulid())
                        ->setUser($managedUser)
                        ->setReservedAt($reservedAt)
                        ->setTotalPrice($this->formatPrice($reservationTotalCents))
                        ->setNbPlaces($groupSize)
                        ->setStatus($status)
                        ->setChannel($channel)
                        ->setSeance($managedScreening);
                    if ($isOnline) {
                        $reservation->setConfirmationEmailSentAt($reservedAt->modify('+'.random_int(1, 5).' minutes'));
                    }
                    $this->entityManager->persist($reservation);

                    foreach ($reservationTickets as $ticketData) {
                        $ticket = (new Billet())
                            ->setReference(new Ulid())
                            ->setTarif($ticketData['tariff'])
                            ->setPriceApplied($ticketData['price'])
                            ->setSeatNumber($ticketData['seatNumber']);
                        $reservation->addBillet($ticket);
                        $this->entityManager->persist($ticket);
                    }

                    ++$reservationsCreated;
                    $ticketsCreated += $groupSize;
                    $pendingTickets += $groupSize;
                    $ticketCount -= $groupSize;

                    if ($pendingTickets >= self::TICKET_BATCH_SIZE) {
                        $this->flushBatch();
                        $pendingTickets = 0;
                    }
                }
            }

            if ($pendingTickets > 0) {
                $this->flushBatch();
                $pendingTickets = 0;
            }
            $this->entityManager->clear();
        }

        return ['reservations' => $reservationsCreated, 'tickets' => $ticketsCreated];
    }

    private function randomGroupSize(): int
    {
        $roll = random_int(1, 100);

        return match (true) {
            $roll <= 25 => 1,
            $roll <= 70 => 2,
            $roll <= 90 => 3,
            default => 4,
        };
    }

    /**
     * @param array<string, Tarif> $tariffsByCode
     */
    private function randomTariff(array $tariffsByCode): Tarif
    {
        $roll = random_int(1, 100);
        $code = match (true) {
            $roll <= 60 => 'PLEIN',
            $roll <= 80 => 'REDUIT',
            $roll <= 90 => 'ETUDIANT',
            $roll <= 96 => 'ENFANT',
            $roll <= 99 => 'SENIOR',
            default => 'CHOMEUR',
        };

        return $tariffsByCode[$code];
    }

    private function saleDate(
        DateTimeImmutable $screeningDateTime,
        DateTimeImmutable $from,
        DateTimeImmutable $recentSalesFrom,
    ): DateTimeImmutable {
        $minimumSaleDate = $screeningDateTime >= $recentSalesFrom ? $recentSalesFrom : $from;
        $maximumLeadMinutes = (int) (($screeningDateTime->getTimestamp() - $minimumSaleDate->getTimestamp()) / 60);
        $maximumLeadMinutes = min($maximumLeadMinutes, self::MAX_SALE_LEAD_DAYS * 24 * 60);
        $minimumLeadMinutes = min(30, $maximumLeadMinutes);
        $leadMinutes = random_int($minimumLeadMinutes, $maximumLeadMinutes);

        return $screeningDateTime->modify(sprintf('-%d minutes', $leadMinutes));
    }

    private function formatSeatNumber(int $seat): string
    {
        $row = chr(65 + intdiv($seat - 1, 20));
        $seatInRow = (($seat - 1) % 20) + 1;

        return sprintf('%s%02d', $row, $seatInRow);
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

    private function flushBatch(): void
    {
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    private function managedScreening(Seance $screening): Seance
    {
        $id = $screening->getId();
        if (null === $id) {
            return $screening;
        }

        $managedScreening = $this->entityManager->getReference(Seance::class, $id);
        if (!$managedScreening instanceof Seance) {
            throw new \LogicException(sprintf('La séance %d ne peut pas être rechargée.', $id));
        }

        return $managedScreening;
    }

    private function managedUser(User $user): User
    {
        $id = $user->getId();
        if (null === $id) {
            return $user;
        }

        $managedUser = $this->entityManager->getReference(User::class, $id);
        if (!$managedUser instanceof User) {
            throw new \LogicException(sprintf('Le compte %s ne peut pas être rechargé.', $id));
        }

        return $managedUser;
    }

    private function managedTariff(Tarif $tariff): Tarif
    {
        $id = $tariff->getId();
        if (null === $id) {
            return $tariff;
        }

        $managedTariff = $this->entityManager->getReference(Tarif::class, $id);
        if (!$managedTariff instanceof Tarif) {
            throw new \LogicException(sprintf('Le tarif %s ne peut pas être rechargé.', $id));
        }

        return $managedTariff;
    }
}
