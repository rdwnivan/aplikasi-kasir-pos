<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Qris = 'qris';
    /** Part cash, the rest by QRIS (DESIGN.md §11). */
    case Split = 'split';
    /** Kasbon: an optional cash down payment, the rest owed by the customer (DESIGN.md §17). */
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Tunai',
            self::Qris => 'QRIS',
            self::Split => 'Tunai + QRIS',
            self::Credit => 'Kasbon',
        };
    }

    /** Paid (at least partly) through Midtrans, so it goes through the QRIS state machine. */
    public function usesQris(): bool
    {
        return in_array($this, self::qrisMethods(), true);
    }

    /** @return list<self> */
    public static function qrisMethods(): array
    {
        return [self::Qris, self::Split];
    }
}
