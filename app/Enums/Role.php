<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Warehouse = 'gudang';
    case Cashier = 'kasir';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Warehouse => 'Staf Gudang',
            self::Cashier => 'Kasir',
        };
    }
}
