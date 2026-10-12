<?php

namespace App\Enum;

enum SeanceStatus: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case CANCELLED = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::DRAFT => 'Brouillon',
            self::PUBLISHED => 'Publiée',
            self::CANCELLED => 'Annulée',
        };
    }
}
