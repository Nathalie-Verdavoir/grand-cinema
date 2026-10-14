<?php

namespace App\Service;

use App\Entity\Reservation;
use LogicException;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

final class InvoiceMailer implements InvoiceSender
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly InvoicePdfGenerator $invoicePdfGenerator,
        private readonly string $mailerFrom,
    ) {
    }

    /** @throws TransportExceptionInterface When the invoice email cannot be delivered. */
    public function send(Reservation $reservation): void
    {
        $reference = $reservation->getReference();
        $recipient = $reservation->getUser()?->getEmail();
        if (null === $reference || null === $recipient || '' === trim($recipient)) {
            throw new LogicException('La réservation doit avoir une référence et une adresse e-mail valide.');
        }

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, 'Grand Cinéma'))
            ->to($recipient)
            ->subject('Votre facture — Grand Cinéma')
            ->htmlTemplate('emails/reservation_invoice.html.twig')
            ->textTemplate('emails/reservation_invoice.txt.twig')
            ->context(['reservation' => $reservation])
            ->attach(
                $this->invoicePdfGenerator->generate($reservation),
                sprintf('facture-reservation-%s.pdf', $reference),
                'application/pdf'
            );

        $this->mailer->send($email);
    }
}
