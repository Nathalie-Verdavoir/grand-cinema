<?php

namespace App\Service;

use App\Entity\Reservation;
use App\Enum\ReservationStatus;
use DateTimeImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use LogicException;
use Twig\Environment;

final class InvoicePdfGenerator
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function generate(Reservation $reservation): string
    {
        $reference = $reservation->getReference();
        $userEmail = $reservation->getUser()?->getEmail();
        $seance = $reservation->getSeance();
        $filmTitle = $seance?->getFilm()?->getTitle();
        $screeningDate = $seance?->getDateTime();
        $roomName = $seance?->getSalle()?->getName();
        $totalPrice = $reservation->getTotalPrice();

        if (
            ReservationStatus::PAID !== $reservation->getStatus()
            || null === $reference
            || null === $userEmail
            || null === $filmTitle
            || null === $screeningDate
            || null === $roomName
            || null === $totalPrice
            || $reservation->getBillets()->isEmpty()
        ) {
            throw new LogicException('Impossible de générer une facture pour cette réservation.');
        }

        $lines = [];
        foreach ($reservation->getBillets() as $billet) {
            $tarifName = $billet->getTarif()?->getName();
            $price = $billet->getPriceApplied();
            if (null === $tarifName || null === $price) {
                throw new LogicException('Un billet ne contient pas toutes les données requises pour la facture.');
            }

            $lines[] = [
                'description' => $billet->getSeatNumber()
                    ? sprintf('%s — place %s', $tarifName, $billet->getSeatNumber())
                    : $tarifName,
                'price' => $price,
            ];
        }

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);

        $pdf = new Dompdf($options);
        $pdf->loadHtml($this->twig->render('emails/reservation_invoice_pdf.html.twig', [
            'reservation' => $reservation,
            'lines' => $lines,
            'issuedAt' => new DateTimeImmutable(),
        ]), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return $pdf->output();
    }
}
