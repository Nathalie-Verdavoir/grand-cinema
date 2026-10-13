<?php

namespace App\Service;

use App\Entity\Reservation;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

final class TicketPdfGenerator
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function generate(Reservation $reservation): string
    {
        $seance = $reservation->getSeance();
        $filmTitle = $seance?->getFilm()?->getTitle();
        $screeningDate = $seance?->getDateTime();
        $roomName = $seance?->getSalle()?->getName();

        if (
            $reservation->getBillets()->isEmpty()
            || null === $filmTitle
            || null === $screeningDate
            || null === $roomName
        ) {
            throw new \LogicException('Impossible de générer un PDF pour une réservation sans billets.');
        }

        $qrCodeWriter = new Writer(new ImageRenderer(new RendererStyle(240, 2), new SvgImageBackEnd()));
        $tickets = [];
        foreach ($reservation->getBillets() as $billet) {
            $tarif = $billet->getTarif();
            $tarifName = $tarif?->getName();
            $billetReference = $billet->getReference();
            $price = $billet->getPriceApplied();
            if (null === $tarifName || null === $billetReference || null === $price) {
                throw new \LogicException('Un billet ne contient pas toutes les données requises pour le PDF.');
            }

            $details = [
                ['label' => 'Séance', 'value' => $screeningDate->format('d/m/Y à H:i')],
                ['label' => 'Salle', 'value' => $roomName],
                [
                    'label' => 'Tarif',
                    'value' => sprintf(
                        '%s · %s €',
                        $tarifName,
                        number_format((float) $price, 2, ',', ' ')
                    ),
                ],
            ];
            if (null !== $billet->getSeatNumber()) {
                $details[] = ['label' => 'Place', 'value' => $billet->getSeatNumber()];
            }
            $details[] = ['label' => 'Référence du billet', 'value' => (string) $billetReference];

            $qrCodeSvg = $qrCodeWriter->writeString(
                (string) $billetReference,
                'UTF-8',
                ErrorCorrectionLevel::H()
            );
            $tickets[] = [
                'title' => $filmTitle,
                'details' => $details,
                'qrCode' => 'data:image/svg+xml;base64,'.base64_encode($qrCodeSvg),
            ];
        }

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);

        $pages = [];
        $sheetWidth = 210;
        $sheetHeight = 297;
        $columns = 4;
        $gap = 2;
        $printMargin = 8;
        $cardWidth = ($sheetWidth - $printMargin * 2 - $gap * ($columns - 1)) / $columns;
        $cardHeight = ($sheetHeight - $printMargin * 2 - $gap * ($columns - 1)) / $columns;
        foreach (array_chunk($tickets, 16) as $pageTickets) {
            foreach ($pageTickets as $index => &$ticket) {
                $ticket['x'] = ($index % $columns) * ($cardWidth + $gap) + 1.5;
                $ticket['y'] = intdiv($index, $columns) * ($cardHeight + $gap) + 1.5;
            }
            unset($ticket);
            $pages[] = $pageTickets;
        }

        $pdf = new Dompdf($options);
        $pdf->loadHtml($this->twig->render('emails/reservation_tickets_pdf.html.twig', [
            'pages' => $pages,
        ]), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return $pdf->output();
    }
}
