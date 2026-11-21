<?php

namespace App\Services\Sales;

final readonly class PricedLine
{
    public function __construct(
        public int $gross,
        public int $discount,
        public int $total,
    ) {}
}
