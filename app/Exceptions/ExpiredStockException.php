<?php

namespace App\Exceptions;

use App\Models\Product;

/**
 * Not enough stock that may still be sold: the rest of it has expired (DESIGN.md §19 P5).
 * A kind of InsufficientStockException, so every place that handles a shortage handles this.
 */
class ExpiredStockException extends InsufficientStockException
{
    public function __construct(Product $product, int $requested, int $available, public readonly int $expired)
    {
        parent::__construct($product, $requested, $available);

        $this->message = sprintf(
            'Stok %s yang belum kedaluwarsa tinggal %d (%d sudah kedaluwarsa).',
            $product->name,
            $available,
            $expired,
        );
    }
}
