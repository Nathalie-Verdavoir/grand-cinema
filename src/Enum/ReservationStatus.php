<?php

namespace App\Enum;

enum ReservationStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case PAID_AT_COUNTER = 'paid_at_counter';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';
    case REFUNDED = 'refunded';

    /**
     * label for the status in the back-office OR for the status badge in the front-office.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => 'En attente de paiement',
            self::PAID => 'Payée',
            self::PAID_AT_COUNTER => 'Payée à la caisse',
            self::CANCELLED => 'Annulée sans remboursement',
            self::EXPIRED => 'Expirée',
            self::REFUNDED => 'Remboursée',
        };
    }

    /**
     *  Bootstrap color class for the status badge in the back-office OR for the status label in the front-office.
     */
    public function getColor(): string
    {
        return match ($this) {
            self::PENDING => 'warning',   // Jaune
            self::PAID => 'success',       // Vert
            self::PAID_AT_COUNTER => 'primary',
            self::CANCELLED => 'danger',   // Rouge
            self::EXPIRED => 'secondary',  // Gris
            self::REFUNDED => 'info',      // Bleu
        };
    }
}