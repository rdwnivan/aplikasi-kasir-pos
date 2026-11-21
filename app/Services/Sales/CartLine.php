<?php

namespace App\Services\Sales;

use App\Enums\DiscountType;

/** One cart line as priced by the server (unit price comes from the catalogue, not the client). */
final readonly class CartLine
{
    public function __construct(
        public int $unitPrice,
        public int $qty,
        public ?DiscountType $discountType = null,
        public int $discountValue = 0,
    ) {}
}
