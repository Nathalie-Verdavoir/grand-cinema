<?php

namespace App\Tests\Service;

use App\Entity\Billet;
use App\Entity\Film;
use App\Entity\Reservation;
use App\Entity\Salle;
use App\Entity\Seance;
use App\Entity\Tarif;
use App\Entity\User;
use App\Enum\ReservationStatus;
use App\Service\InvoiceMailer;
use App\Service\InvoicePdfGenerator;
use DateTime;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Uid\Ulid;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class InvoiceMailerTest extends TestCase
{
    public function testItSendsTheInvoicePdfToTheReservationOwner(): void
    {
        $reference = new Ulid();
        $mailerFrom = 'mailer@example.com';
        $reservation = (new Reservation())
            ->setReference($reference)
            ->setUser((new User())->setEmail('spectateur@example.com'))
            ->setStatus(ReservationStatus::PAID)
            ->setSeance((new Seance())
                ->setDateTime(new DateTime('2026-10-20 18:00'))
                ->setFilm((new Film())->setTitle('Film de test'))
                ->setSalle((new Salle())->setName('Salle 1')->setCapacity(100)))
            ->setTotalPrice('8.50')
            ->setNbPlaces(1)
            ->addBillet((new Billet())
                ->setReference(new Ulid())
                ->setTarif((new Tarif())->setName('Plein tarif')->setPrice('8.50')->setCode('PLEIN'))
                ->setPriceApplied('8.50')
                ->setSeatNumber('A1'));

        $mailer = $this->createMock(MailerInterface::class);
        $mailer
            ->expects(self::once())
            ->method('send')
            ->with(self::callback(static function (TemplatedEmail $email) use ($reference, $mailerFrom): bool {
                self::assertSame(['spectateur@example.com'], array_map(
                    static fn (Address $address): string => $address->getAddress(),
                    $email->getTo()
                ));
                self::assertSame($mailerFrom, $email->getFrom()[0]->getAddress());
                self::assertCount(1, $email->getAttachments());
                self::assertSame(sprintf('facture-reservation-%s.pdf', $reference), $email->getAttachments()[0]->getFilename());
                self::assertSame('application/pdf', $email->getAttachments()[0]->getContentType());
                self::assertStringStartsWith('%PDF-', $email->getAttachments()[0]->getBody());

                return true;
            }));

        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2).'/templates'));
        $invoiceMailer = new InvoiceMailer($mailer, new InvoicePdfGenerator($twig), $mailerFrom);

        $invoiceMailer->send($reservation);
    }

    public function testItRejectsUnpaidReservations(): void
    {
        $generator = new InvoicePdfGenerator(new Environment(new FilesystemLoader(dirname(__DIR__, 2).'/templates')));

        $this->expectException(LogicException::class);
        $generator->generate(new Reservation());
    }
}
