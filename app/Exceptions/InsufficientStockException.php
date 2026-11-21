<?php

namespace App\Exceptions;

use App\Models\Product;
use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly Product $product,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct(sprintf(
            'Stok %s tidak cukup: diminta %d, tersedia %d.',
            $product->name,
            $requested,
            $available,
        ));
    }
}
