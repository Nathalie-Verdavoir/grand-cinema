<?php

namespace App\Tests\Twig;

use App\Entity\Billet;
use App\Entity\Film;
use App\Entity\Reservation;
use App\Entity\Salle;
use App\Entity\Seance;
use App\Entity\Tarif;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class TicketsTemplateTest extends TestCase
{
    public function testEachTicketRendersAQrCodeCanvasWithItsReferenceAndThePoster(): void
    {
        $first = new Ulid();
        $second = new Ulid();
        $reservation = $this->reservation('https://example.test/affiche.jpg', [$first, $second]);

        $html = $this->render($reservation);

        self::assertSame(2, substr_count($html, 'data-ticket-qr="'));
        self::assertStringContainsString('data-ticket-qr="'.$first.'"', $html);
        self::assertStringContainsString('data-ticket-qr="'.$second.'"', $html);
        self::assertSame(2, substr_count($html, 'data-ticket-poster="https://example.test/affiche.jpg"'));
        self::assertSame(2, substr_count($html, '<canvas'));
    }

    public function testQrCodeHasNoPosterAttributeWhenTheFilmHasNoImage(): void
    {
        $html = $this->render($this->reservation(null, [new Ulid()]));

        self::assertStringContainsString('data-ticket-qr="', $html);
        self::assertStringNotContainsString('data-ticket-poster', $html);
    }

    /**
     * @param list<Ulid> $references
     */
    private function reservation(?string $poster, array $references): Reservation
    {
        $film = (new Film())->setTitle('Film test')->setImg($poster);
        $salle = (new Salle())->setName('Salle 1');
        $seance = (new Seance())
            ->setFilm($film)
            ->setSalle($salle)
            ->setDateTime(new \DateTime('2026-10-20 20:00:00'));
        $tarif = (new Tarif())->setName('Normal')->setPrice('8.50')->setCode('normal');

        $reservation = (new Reservation())->setSeance($seance);
        foreach ($references as $reference) {
            $reservation->addBillet(
                (new Billet())->setTarif($tarif)->setPriceApplied('8.50')->setReference($reference)
            );
        }

        return $reservation;
    }

    private function render(Reservation $reservation): string
    {
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['base.html.twig' => '{% block title %}{% endblock %}{% block body %}{% endblock %}']),
            new FilesystemLoader(dirname(__DIR__, 2).'/templates'),
        ]));
        $twig->addFunction(new TwigFunction('path', static fn (string $name): string => '/'.$name));

        return $twig->render('reservation/tickets.html.twig', ['reservation' => $reservation]);
    }
}
