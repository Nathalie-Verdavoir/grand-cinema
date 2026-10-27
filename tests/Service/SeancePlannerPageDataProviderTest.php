<?php

namespace App\Tests\Service;

use App\Entity\Film;
use App\Entity\Salle;
use App\Entity\Seance;
use App\Repository\FilmRepository;
use App\Repository\SalleRepository;
use App\Repository\SeanceRepository;
use App\Service\DateFormatter;
use App\Service\ScreeningAvailability;
use App\Service\SeancePlannerPageDataProvider;
use DateTime;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;

final class SeancePlannerPageDataProviderTest extends TestCase
{
    public function testWeekViewIncludesAnOvernightScreeningAsAContinuationOnTheFollowingDay(): void
    {
        $room = (new Salle())
            ->setName('Salle 1')
            ->setCapacity(50);
        $film = (new Film())
            ->setTitle('Film de nuit')
            ->setDuration(100);
        $seance = (new Seance())
            ->setFilm($film)
            ->setSalle($room)
            ->setDateTime(new DateTime('2026-10-18 23:00:00'));
        (new ReflectionClass(Seance::class))->getProperty('id')->setValue($seance, 12);

        $filmRepository = $this->createMock(FilmRepository::class);
        $filmRepository->method('findBy')->willReturn([]);

        $salleRepository = $this->createMock(SalleRepository::class);
        $salleRepository->method('findAll')->willReturn([$room]);

        $seanceRepository = $this->createMock(SeanceRepository::class);
        $seanceRepository->method('findForPlannerRoomBetween')->willReturn([$seance]);
        $seanceRepository->method('findOccupancyBySeanceIds')->with([12])->willReturn([
            12 => ['reservedSeats' => 18, 'occupancyPercent' => 36],
        ]);

        $adminUrlGenerator = (new ReflectionClass(AdminUrlGenerator::class))->newInstanceWithoutConstructor();
        $provider = new SeancePlannerPageDataProvider(
            $filmRepository,
            $salleRepository,
            $seanceRepository,
            $adminUrlGenerator,
            new DateFormatter(),
            new ScreeningAvailability(),
        );

        $data = $provider->build(new Request([
            'view' => 'week',
            'week' => '2026-10-19',
        ]));

        self::assertCount(1, $data['eventsByDay']['2026-10-19']);
        self::assertSame('Film de nuit', $data['eventsByDay']['2026-10-19'][0]['title']);
        self::assertTrue($data['eventsByDay']['2026-10-19'][0]['continuation']);
        self::assertSame('23:00', $data['eventsByDay']['2026-10-19'][0]['start']);
        self::assertSame(18, $data['eventsByDay']['2026-10-19'][0]['reservedSeats']);
        self::assertSame(36, $data['eventsByDay']['2026-10-19'][0]['occupancyPercent']);
    }
}
