<?php

namespace App\Tests\Service;

use App\Service\KpiPdfGenerator;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class KpiPdfGeneratorTest extends TestCase
{
    public function testItGeneratesAPdfReportForTheSelectedPeriod(): void
    {
        $kpis = [
            'entries' => 20,
            'revenue' => 180.0,
            'averageRevenuePerEntry' => 9.0,
            'occupancyPercent' => 50.0,
            'activeScreens' => 2,
            'revenuePerScreen' => 90.0,
            'onlineReservations' => 8,
            'counterReservations' => 2,
            'onlinePercent' => 80.0,
            'counterPercent' => 20.0,
            'occupancyChartPercent' => 50.0,
            'salesByFilm' => [['title' => 'Film de test', 'entries' => 20, 'revenue' => 180.0, 'barPercent' => 100.0]],
            'salesByWeekday' => [['label' => 'Mercredi', 'entries' => 20, 'barPercent' => 100.0]],
            'salesByTimeOfDay' => [['label' => '18 h – 20 h', 'entries' => 20, 'barPercent' => 100.0]],
        ];
        $report = [
            'from' => new DateTimeImmutable('2026-10-01'),
            'to' => new DateTimeImmutable('2026-10-14'),
            'kpis' => $kpis,
            'previousFrom' => new DateTimeImmutable('2026-09-01'),
            'previousTo' => new DateTimeImmutable('2026-09-14'),
            'previousMonthKpis' => $kpis,
            'annualYear' => 2026,
            'annualKpis' => $kpis,
        ];
        $loader = new FilesystemLoader(dirname(__DIR__, 2).'/templates');
        $generator = new KpiPdfGenerator(new Environment($loader));

        self::assertStringStartsWith('%PDF-', $generator->generate($report));
    }
}
