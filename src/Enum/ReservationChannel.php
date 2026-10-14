<?php

namespace App\Enum;

enum ReservationChannel: string
{
    case ONLINE = 'online';
    case COUNTER = 'counter';

    public function getLabel(): string
    {
        return match ($this) {
            self::ONLINE => 'En ligne',
            self::COUNTER => 'Guichet',
        };
    }
}
