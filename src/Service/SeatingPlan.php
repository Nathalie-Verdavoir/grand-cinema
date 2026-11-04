<?php

namespace App\Service;

final class SeatingPlan
{
    public const SEATS_PER_ROW = 10;

    /**
     * @return array<string, list<string>> seat labels (e.g. "A1") indexed by row letter
     */
    public function rows(int $capacity): array
    {
        $rows = [];
        for ($index = 0; $index < max(0, $capacity); ++$index) {
            $rowLabel = $this->rowLabel(intdiv($index, self::SEATS_PER_ROW));
            $rows[$rowLabel][] = $rowLabel.($index % self::SEATS_PER_ROW + 1);
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    public function seats(int $capacity): array
    {
        return array_merge([], ...array_values($this->rows($capacity)));
    }

    private function rowLabel(int $rowIndex): string
    {
        $label = '';
        do {
            $label = chr(65 + $rowIndex % 26).$label;
            $rowIndex = intdiv($rowIndex, 26) - 1;
        } while ($rowIndex >= 0);

        return $label;
    }
}
