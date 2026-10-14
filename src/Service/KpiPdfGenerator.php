<?php

namespace App\Service;

use DateTimeImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

final class KpiPdfGenerator
{
    public function __construct(private readonly Environment $twig)
    {
    }

    /**
     * @param array{
     *     from: DateTimeImmutable,
     *     to: DateTimeImmutable,
     *     kpis: array<string, mixed>,
     *     previousFrom: DateTimeImmutable,
     *     previousTo: DateTimeImmutable,
     *     previousMonthKpis: array<string, mixed>,
     *     annualYear: int,
     *     annualKpis: array<string, mixed>
     * } $report
     */
    public function generate(array $report): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);

        $pdf = new Dompdf($options);
        $pdf->loadHtml($this->twig->render('admin/kpis_pdf.html.twig', $report), 'UTF-8');
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();

        return $pdf->output();
    }
}
