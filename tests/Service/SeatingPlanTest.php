<?php

namespace App\Tests\Service;

use App\Service\SeatingPlan;
use PHPUnit\Framework\TestCase;

final class SeatingPlanTest extends TestCase
{
    public function testItGeneratesRowsOfTenSeatsWithLetterAndNumberLabels(): void
    {
        $rows = (new SeatingPlan())->rows(12);

        self::assertSame(['A', 'B'], array_keys($rows));
        self::assertSame(
            ['A1', 'A2', 'A3', 'A4', 'A5', 'A6', 'A7', 'A8', 'A9', 'A10'],
            $rows['A']
        );
        self::assertSame(['B1', 'B2'], $rows['B']);
    }

    public function testItContinuesRowLabelsAfterZ(): void
    {
        $seats = (new SeatingPlan())->seats(27 * 10);

        self::assertCount(270, $seats);
        self::assertContains('Z10', $seats);
        self::assertContains('AA1', $seats);
        self::assertSame('AA10', $seats[array_key_last($seats)]);
    }

    public function testItReturnsNoSeatForAnEmptyCapacity(): void
    {
        self::assertSame([], (new SeatingPlan())->seats(0));
        self::assertSame([], (new SeatingPlan())->seats(-5));
    }
}
