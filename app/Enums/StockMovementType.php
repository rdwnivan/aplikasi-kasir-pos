<?php

namespace App\Enums;

enum StockMovementType: string
{
    case Purchase = 'purchase';
    case Sale = 'sale';
    case SaleReturn = 'sale_return';
    case Adjustment = 'adjustment';
    case Opname = 'opname';

    /**
     * Movement types whose incoming cost re-weights the moving-average HPP.
     * Other incoming types (e.g. a positive opname correction) enter at the current average.
     */
    public function revaluesAverage(): bool
    {
        return in_array($this, [self::Purchase, self::SaleReturn], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Penerimaan barang',
            self::Sale => 'Penjualan',
            self::SaleReturn => 'Retur penjualan',
            self::Adjustment => 'Penyesuaian',
            self::Opname => 'Stok opname',
        };
    }
}
