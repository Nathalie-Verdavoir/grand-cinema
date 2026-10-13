<?php

namespace App\Twig;

use App\Entity\Billet;
use App\Entity\Reservation;
use App\Entity\User;
use App\Repository\ReservationRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ReservationExtension extends AbstractExtension
{
    public function __construct(private readonly ReservationRepository $reservationRepository)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('pending_reservation_seat_count', $this->countPendingSeats(...)),
            new TwigFunction('reservation_ticket_summary', $this->summarizeTickets(...)),
            new TwigFunction('reservation_expiration_timestamp', $this->expirationTimestamp(...)),
        ];
    }

    public function expirationTimestamp(Reservation $reservation): int
    {
        $expiresAt = $reservation->getExpiresAt();
        if (null === $expiresAt) {
            throw new \LogicException('Une réservation doit avoir une date de réservation.');
        }

        return $expiresAt->getTimestamp();
    }

    public function countPendingSeats(User $user): int
    {
        return $this->reservationRepository->countPendingSeatsForUser($user);
    }

    /**
     * @return list<array{tarif: string, quantity: int, unitPrice: string, subtotal: string}>
     */
    public function summarizeTickets(Reservation $reservation): array
    {
        $summary = [];
        foreach ($reservation->getBillets() as $billet) {
            if (!$billet instanceof Billet) {
                throw new \LogicException('La réservation contient un élément qui n’est pas un billet.');
            }

            $tarif = $billet->getTarif();
            if (null === $tarif || null === $tarif->getId()) {
                throw new \LogicException('Chaque billet doit être lié à un tarif enregistré.');
            }

            $tarifName = $tarif->getName();
            if (null === $tarifName) {
                throw new \LogicException('Chaque tarif de billet doit posséder un nom.');
            }

            $tarifId = (string) $tarif->getId();
            $priceInCents = $this->priceInCents($billet);
            $key = $tarifId.':'.$priceInCents;
            if (!isset($summary[$key])) {
                $summary[$key] = [
                    'tarif' => $tarifName,
                    'quantity' => 0,
                    'unitPriceCents' => $priceInCents,
                    'subtotalCents' => 0,
                ];
            }
            ++$summary[$key]['quantity'];
            $summary[$key]['subtotalCents'] += $priceInCents;
        }

        $ticketGroups = [];
        foreach ($summary as $ticketGroup) {
            $ticketGroups[] = [
                'tarif' => $ticketGroup['tarif'],
                'quantity' => $ticketGroup['quantity'],
                'unitPrice' => $this->formatPrice($ticketGroup['unitPriceCents']),
                'subtotal' => $this->formatPrice($ticketGroup['subtotalCents']),
            ];
        }

        return $ticketGroups;
    }

    private function priceInCents(Billet $billet): int
    {
        $price = $billet->getPriceApplied();
        if (!is_string($price) || !preg_match('/^\d+(?:\.\d{1,2})?$/D', $price)) {
            throw new \LogicException('Le prix appliqué au billet est invalide.');
        }

        [$euros, $cents] = array_pad(explode('.', $price, 2), 2, '');

        return (int) $euros * 100 + (int) str_pad($cents, 2, '0');
    }

    private function formatPrice(int $priceInCents): string
    {
        return sprintf('%d.%02d', intdiv($priceInCents, 100), $priceInCents % 100);
    }
}
