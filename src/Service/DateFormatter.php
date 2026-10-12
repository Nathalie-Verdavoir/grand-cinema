<?php

namespace App\Service;

use DateTimeInterface;

final class DateFormatter
{
    private const WEEKDAYS = [
        'fr' => [
            1 => ['short' => 'lun.', 'long' => 'Lundi'],
            2 => ['short' => 'mar.', 'long' => 'Mardi'],
            3 => ['short' => 'mer.', 'long' => 'Mercredi'],
            4 => ['short' => 'jeu.', 'long' => 'Jeudi'],
            5 => ['short' => 'ven.', 'long' => 'Vendredi'],
            6 => ['short' => 'sam.', 'long' => 'Samedi'],
            7 => ['short' => 'dim.', 'long' => 'Dimanche'],
        ],
        'en' => [
            1 => ['short' => 'Mon.', 'long' => 'Monday'],
            2 => ['short' => 'Tue.', 'long' => 'Tuesday'],
            3 => ['short' => 'Wed.', 'long' => 'Wednesday'],
            4 => ['short' => 'Thu.', 'long' => 'Thursday'],
            5 => ['short' => 'Fri.', 'long' => 'Friday'],
            6 => ['short' => 'Sat.', 'long' => 'Saturday'],
            7 => ['short' => 'Sun.', 'long' => 'Sunday'],
        ],
    ];

    private const MONTHS = [
        'fr' => [
            1 => 'janv.', 2 => 'févr.', 3 => 'mars', 4 => 'avr.',
            5 => 'mai', 6 => 'juin', 7 => 'juil.', 8 => 'août',
            9 => 'sept.', 10 => 'oct.', 11 => 'nov.', 12 => 'déc.',
        ],
        'en' => [
            1 => 'Jan.', 2 => 'Feb.', 3 => 'Mar.', 4 => 'Apr.',
            5 => 'May', 6 => 'Jun.', 7 => 'Jul.', 8 => 'Aug.',
            9 => 'Sep.', 10 => 'Oct.', 11 => 'Nov.', 12 => 'Dec.',
        ],
    ];

    public function weekday(DateTimeInterface $date, bool $short = false, string $locale = 'fr'): string
    {
        $locale = $this->normalizeLocale($locale);

        return self::WEEKDAYS[$locale][(int) $date->format('N')][$short ? 'short' : 'long'];
    }

    public function month(DateTimeInterface $date, string $locale = 'fr'): string
    {
        return self::MONTHS[$this->normalizeLocale($locale)][(int) $date->format('n')];
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = strtolower(substr(str_replace('_', '-', $locale), 0, 2));

        if (!isset(self::WEEKDAYS[$locale])) {
            throw new \InvalidArgumentException(sprintf('Unsupported date locale "%s".', $locale));
        }

        return $locale;
    }
}
