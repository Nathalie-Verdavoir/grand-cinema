<?php

namespace App\Tests\Service;

use App\Entity\Billet;
use App\Entity\Film;
use App\Entity\Reservation;
use App\Entity\Salle;
use App\Entity\Seance;
use App\Entity\Tarif;
use App\Entity\User;
use App\Service\ReservationConfirmationMailer;
use App\Service\TicketPdfGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Uid\Ulid;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ReservationConfirmationMailerTest extends TestCase
{
    public function testItSendsAConfirmationEmailWithTheTicketPdfAttached(): void
    {
        $reference = new Ulid();
        $mailerFrom = 'mailer@example.com';
        $billet = (new Billet())
            ->setReference(new Ulid())
            ->setTarif((new Tarif())->setName('Plein tarif')->setPrice('8.50')->setCode('PLEIN'))
            ->setPriceApplied('8.50')
            ->setSeatNumber('A1');
        $reservation = (new Reservation())
            ->setReference($reference)
            ->setUser((new User())->setEmail('spectateur@example.com'))
            ->setSeance((new Seance())
                ->setDateTime(new \DateTime('2026-10-20 18:00'))
                ->setFilm((new Film())->setTitle('Film de test'))
                ->setSalle((new Salle())->setName('Salle 1')->setCapacity(100)))
            ->setTotalPrice('8.50')
            ->setNbPlaces(1)
            ->addBillet($billet);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer
            ->expects(self::once())
            ->method('send')
            ->with(self::callback(static function (TemplatedEmail $email) use ($reference, $mailerFrom): bool {
                self::assertSame($mailerFrom, $email->getFrom()[0]->getAddress());
                $attachments = $email->getAttachments();
                self::assertCount(1, $attachments);
                self::assertSame(sprintf('billets-reservation-%s.pdf', $reference), $attachments[0]->getFilename());
                self::assertSame('application/pdf', $attachments[0]->getContentType());
                self::assertStringStartsWith('%PDF-', $attachments[0]->getBody());

                return true;
            }));

        $loader = new FilesystemLoader(dirname(__DIR__, 2).'/templates');
        $pdfGenerator = new TicketPdfGenerator(new Environment($loader));
        $confirmationMailer = new ReservationConfirmationMailer($mailer, $pdfGenerator, $mailerFrom);

        $confirmationMailer->send($reservation);
    }
}
