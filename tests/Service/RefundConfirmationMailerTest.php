<?php

namespace App\Tests\Service;

use App\Entity\Film;
use App\Entity\Reservation;
use App\Entity\Salle;
use App\Entity\Seance;
use App\Entity\User;
use App\Enum\ReservationStatus;
use App\Service\RefundConfirmationMailer;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Uid\Ulid;

final class RefundConfirmationMailerTest extends TestCase
{
    public function testItSendsARefundConfirmationEmailToTheReservationOwner(): void
    {
        $reservation = $this->refundedReservation();
        $mailer = $this->createMock(MailerInterface::class);
        $mailer
            ->expects(self::once())
            ->method('send')
            ->with(self::callback(static function (TemplatedEmail $email): bool {
                self::assertSame('Confirmation de remboursement — Grand Cinéma', $email->getSubject());
                self::assertSame(['spectateur@example.com'], array_map(
                    static fn ($address): string => $address->getAddress(),
                    $email->getTo()
                ));

                return true;
            }));

        (new RefundConfirmationMailer($mailer))->send($reservation);
    }

    public function testItRejectsReservationsThatHaveNotBeenRefunded(): void
    {
        $reservation = $this->refundedReservation()->setStatus(ReservationStatus::PAID);
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $this->expectException(LogicException::class);
        (new RefundConfirmationMailer($mailer))->send($reservation);
    }

    private function refundedReservation(): Reservation
    {
        return (new Reservation())
            ->setReference(new Ulid())
            ->setUser((new User())->setEmail('spectateur@example.com'))
            ->setStatus(ReservationStatus::REFUNDED)
            ->setSeance((new Seance())
                ->setDateTime(new \DateTime('2026-10-20 18:00'))
                ->setFilm((new Film())->setTitle('Film de test'))
                ->setSalle((new Salle())->setName('Salle 1')->setCapacity(100)))
            ->setTotalPrice('8.50')
            ->setNbPlaces(1);
    }
}
