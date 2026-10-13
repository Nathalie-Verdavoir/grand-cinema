<?php

namespace App\Tests\Twig;

use App\Entity\Billet;
use App\Entity\Reservation;
use App\Entity\Tarif;
use App\Repository\ReservationRepository;
use App\Twig\ReservationExtension;
use PHPUnit\Framework\TestCase;

final class ReservationExtensionTest extends TestCase
{
    public function testItGroupsTicketsByTariffAndAppliedPrice(): void
    {
        $normalTarif = $this->tarif(1, 'Tarif Normal', '8.50');
        $seniorTarif = $this->tarif(2, 'Tarif +65', '6.50');
        $reservation = new Reservation();
        $reservation
            ->addBillet((new Billet())->setTarif($normalTarif)->setPriceApplied('8.50'))
            ->addBillet((new Billet())->setTarif($normalTarif)->setPriceApplied('8.50'))
            ->addBillet((new Billet())->setTarif($seniorTarif)->setPriceApplied('7.00'));

        $extension = new ReservationExtension($this->createMock(ReservationRepository::class));

        self::assertSame([
            ['tarif' => 'Tarif Normal', 'quantity' => 2, 'unitPrice' => '8.50', 'subtotal' => '17.00'],
            ['tarif' => 'Tarif +65', 'quantity' => 1, 'unitPrice' => '7.00', 'subtotal' => '7.00'],
        ], $extension->summarizeTickets($reservation));
    }

    public function testItCalculatesTheExpirationTimestampFromTheReservationTime(): void
    {
        $reservedAt = new \DateTimeImmutable('2026-10-13 12:00:00');
        $reservation = (new Reservation())->setReservedAt($reservedAt);
        $extension = new ReservationExtension($this->createMock(ReservationRepository::class));

        self::assertSame(
            $reservedAt->modify('+15 minutes')->getTimestamp(),
            $extension->expirationTimestamp($reservation)
        );
    }

    private function tarif(int $id, string $name, string $price): Tarif
    {
        $tarif = (new Tarif())->setName($name)->setPrice($price)->setCode('normal');
        (new \ReflectionProperty(Tarif::class, 'id'))->setValue($tarif, $id);

        return $tarif;
    }
}
