<?php

namespace App\Tests\Service;

use App\Entity\Film;
use App\Entity\Seance;
use App\Enum\SeanceStatus;
use App\Service\DateFormatter;
use App\Service\ScreeningFormatter;
use DateTime;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ScreeningFormatterTest extends TestCase
{
    public function testInProgressScreeningRemainsAvailableInUpcomingListButIsNotBookable(): void
    {
        $now = new DateTimeImmutable('2026-10-13 15:00:00');
        $screening = $this->screening(new DateTimeImmutable('2026-10-13 14:00:00'));
        $formatter = new ScreeningFormatter(new DateFormatter());

        self::assertTrue($formatter->isInProgress($screening, $now));
        self::assertTrue($formatter->isUpcomingOrInProgress($screening, $now));
        self::assertFalse($formatter->isBookable($screening, $now));
        self::assertSame([
            'id' => null,
            'date' => 'mar. 13 oct.',
            'time' => '14:00',
            'room' => null,
            'inProgress' => true,
        ], $formatter->formatUpcoming([$screening], $now)[0]);
    }

    public function testScreeningIsNoLongerInProgressAtItsEndTime(): void
    {
        $now = new DateTimeImmutable('2026-10-13 16:00:00');
        $screening = $this->screening(new DateTimeImmutable('2026-10-13 14:00:00'));
        $formatter = new ScreeningFormatter(new DateFormatter());

        self::assertFalse($formatter->isInProgress($screening, $now));
        self::assertFalse($formatter->isUpcomingOrInProgress($screening, $now));
        self::assertFalse($formatter->isBookable($screening, $now));
        self::assertSame([], $formatter->formatUpcoming([$screening], $now));
    }

    public function testFutureScreeningIsAvailableButNotMarkedAsInProgress(): void
    {
        $now = new DateTimeImmutable('2026-10-13 15:00:00');
        $screening = $this->screening(new DateTimeImmutable('2026-10-13 16:00:00'));
        $formatter = new ScreeningFormatter(new DateFormatter());

        self::assertFalse($formatter->isInProgress($screening, $now));
        self::assertTrue($formatter->isUpcomingOrInProgress($screening, $now));
        self::assertTrue($formatter->isBookable($screening, $now));
        self::assertFalse($formatter->formatUpcoming([$screening], $now)[0]['inProgress']);
    }

    public function testScreeningStartingNowIsVisibleButNotBookable(): void
    {
        $now = new DateTimeImmutable('2026-10-13 15:00:00');
        $screening = $this->screening($now);
        $formatter = new ScreeningFormatter(new DateFormatter());

        self::assertTrue($formatter->isInProgress($screening, $now));
        self::assertTrue($formatter->isUpcomingOrInProgress($screening, $now));
        self::assertFalse($formatter->isBookable($screening, $now));
    }

    private function screening(DateTimeImmutable $start): Seance
    {
        return (new Seance())
            ->setFilm((new Film())->setTitle('Film test')->setDuration(120))
            ->setDateTime(DateTime::createFromImmutable($start))
            ->setStatus(SeanceStatus::PUBLISHED);
    }
}
