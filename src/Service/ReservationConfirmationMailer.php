<?php

namespace App\Service;

use App\Entity\Reservation;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

final class ReservationConfirmationMailer implements ReservationConfirmationSender
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TicketPdfGenerator $ticketPdfGenerator,
    ) {
    }

    public function send(Reservation $reservation): void
    {
        $reference = $reservation->getReference();
        $recipient = $reservation->getUser()?->getEmail();
        if (null === $reference || null === $recipient || '' === trim($recipient)) {
            throw new \LogicException('La réservation doit avoir une référence et une adresse e-mail valide.');
        }

        $email = (new TemplatedEmail())
            ->from(new Address('mailer@grand-cinema.com', 'Grand Cinéma'))
            ->to($recipient)
            ->subject('Confirmation de votre réservation — Grand Cinéma')
            ->htmlTemplate('emails/reservation_confirmation.html.twig')
            ->textTemplate('emails/reservation_confirmation.txt.twig')
            ->context(['reservation' => $reservation])
            ->attach(
                $this->ticketPdfGenerator->generate($reservation),
                sprintf('billets-reservation-%s.pdf', $reference),
                'application/pdf'
            );

        $this->mailer->send($email);
    }
}
