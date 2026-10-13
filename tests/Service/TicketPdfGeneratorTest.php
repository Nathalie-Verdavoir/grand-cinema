<?php

namespace App\Tests\Service;

use App\Entity\Billet;
use App\Entity\Film;
use App\Entity\Reservation;
use App\Entity\Salle;
use App\Entity\Seance;
use App\Entity\Tarif;
use App\Service\TicketPdfGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class TicketPdfGeneratorTest extends TestCase
{
    public function testItGeneratesAPdfContainingTheReservationTickets(): void
    {
        $film = (new Film())->setTitle('Film de test');
        $salle = (new Salle())->setName('Salle 1')->setCapacity(100);
        $seance = (new Seance())
            ->setDateTime(new \DateTime('2026-10-20 18:00'))
            ->setFilm($film)
            ->setSalle($salle);
        $tarif = (new Tarif())
            ->setName('Plein tarif')
            ->setPrice('8.50')
            ->setCode('PLEIN');
        $billet = (new Billet())
            ->setReference(new Ulid())
            ->setTarif($tarif)
            ->setPriceApplied('8.50')
            ->setSeatNumber('A1');
        $reservation = (new Reservation())
            ->setReference(new Ulid())
            ->setSeance($seance)
            ->setTotalPrice('8.50')
            ->setNbPlaces(1)
            ->addBillet($billet);

        $loader = new FilesystemLoader(dirname(__DIR__, 2).'/templates');
        $generator = new TicketPdfGenerator(new Environment($loader));

        self::assertStringStartsWith('%PDF-', $generator->generate($reservation));
    }

    public function testItGroupsSixteenTicketsPerPage(): void
    {
        $film = (new Film())->setTitle('Film de test');
        $salle = (new Salle())->setName('Salle 1')->setCapacity(100);
        $seance = (new Seance())
            ->setDateTime(new \DateTime('2026-10-20 18:00'))
            ->setFilm($film)
            ->setSalle($salle);
        $tarif = (new Tarif())
            ->setName('Plein tarif')
            ->setPrice('8.50')
            ->setCode('PLEIN');
        $reservation = (new Reservation())
            ->setReference(new Ulid())
            ->setSeance($seance)
            ->setTotalPrice('144.50')
            ->setNbPlaces(17);

        for ($index = 0; $index < 17; ++$index) {
            $reservation->addBillet((new Billet())
                ->setReference(new Ulid())
                ->setTarif($tarif)
                ->setPriceApplied('8.50'));
        }

        $loader = new FilesystemLoader(dirname(__DIR__, 2).'/templates');
        $generator = new TicketPdfGenerator(new Environment($loader));

        $pdf = $generator->generate($reservation);
        preg_match_all('/\/Type\s*\/Page\b/', $pdf, $pages);

        self::assertCount(2, $pages[0]);
    }

    public function testItRejectsReservationsWithoutTickets(): void
    {
        $generator = new TicketPdfGenerator(new Environment(new FilesystemLoader(dirname(__DIR__, 2).'/templates')));

        $this->expectException(\LogicException::class);
        $generator->generate(new Reservation());
    }
}
