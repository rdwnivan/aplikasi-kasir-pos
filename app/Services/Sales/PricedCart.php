<?php

namespace App\Services\Sales;

final readonly class PricedCart
{
    /** @param list<PricedLine> $lines */
    public function __construct(
        public array $lines,
        public int $subtotal,
        public int $discount,
        public int $taxableAmount,
        public int $dppAmount,
        public int $taxAmount,
        public int $total,
    ) {}
}
