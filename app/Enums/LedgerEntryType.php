<?php

namespace App\Enums;

/** Rows of the customer credit ledger (DESIGN.md §17). */
enum LedgerEntryType: string
{
    /** Kasbon given: the debt goes up. */
    case Sale = 'sale';
    /** Paid off (pelunasan), allocated oldest kasbon first. */
    case Payment = 'payment';
    /** Goods returned from a kasbon sale, taken off its debt. */
    case SaleReturn = 'sale_return';
    /** Written off by an owner. */
    case WriteOff = 'write_off';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Kasbon',
            self::Payment => 'Pelunasan',
            self::SaleReturn => 'Retur',
            self::WriteOff => 'Dihapuskan',
        };
    }
}
