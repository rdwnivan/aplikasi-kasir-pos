<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Qris = 'qris';
    /** Part cash, the rest by QRIS (DESIGN.md §11). */
    case Split = 'split';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Tunai',
            self::Qris => 'QRIS',
            self::Split => 'Tunai + QRIS',
        };
    }

    /** Paid (at least partly) through Midtrans, so it goes through the QRIS state machine. */
    public function usesQris(): bool
    {
        return $this !== self::Cash;
    }

    /** @return list<self> */
    public static function qrisMethods(): array
    {
        return [self::Qris, self::Split];
    }
}
