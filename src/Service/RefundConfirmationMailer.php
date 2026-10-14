<?php

namespace App\Service;

use App\Entity\Reservation;
use App\Enum\ReservationStatus;
use LogicException;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

final class RefundConfirmationMailer implements RefundConfirmationSender
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $mailerFrom,
    ) {
    }

    /** @throws TransportExceptionInterface When the confirmation email cannot be delivered. */
    public function send(Reservation $reservation): void
    {
        $reference = $reservation->getReference();
        $recipient = $reservation->getUser()?->getEmail();
        if (
            ReservationStatus::REFUNDED !== $reservation->getStatus()
            || null === $reference
            || null === $recipient
            || '' === trim($recipient)
        ) {
            throw new LogicException('La réservation remboursée doit avoir une référence et une adresse e-mail valide.');
        }

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, 'Grand Cinéma'))
            ->to($recipient)
            ->subject('Confirmation de remboursement — Grand Cinéma')
            ->htmlTemplate('emails/refund_confirmation.html.twig')
            ->textTemplate('emails/refund_confirmation.txt.twig')
            ->context(['reservation' => $reservation]);

        $this->mailer->send($email);
    }
}
