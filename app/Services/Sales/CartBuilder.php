<?php

namespace App\Services\Sales;

use App\Enums\DiscountType;
use App\Models\ProductUnit;
use Illuminate\Validation\ValidationException;

/**
 * Turns what the cashier screen sends (product unit + qty + optional discount) into a priced
 * cart. Prices are always read from the catalogue; the client never supplies a price.
 */
final class CartBuilder
{
    public function __construct(private readonly PriceCalculator $calculator) {}

    /**
     * @param  list<array{product_unit_id: int, qty: int, discount_type?: string|null, discount_value?: int|null}>  $input
     */
    public function build(
        array $input,
        ?DiscountType $discountType = null,
        int $discountValue = 0,
        ?TaxSettings $tax = null,
    ): Cart {
        $tax ??= TaxSettings::fromConfig();

        $found = ProductUnit::query()
            ->with(['product', 'unit'])
            ->whereIn('id', array_column($input, 'product_unit_id'))
            ->get()
            ->keyBy('id');

        $units = [];
        $lines = [];
        $errors = [];

        foreach ($input as $i => $row) {
            $unit = $found->get($row['product_unit_id'] ?? 0);

            if ($unit === null || $unit->product === null || ! $unit->product->is_active) {
                $errors["items.$i.product_unit_id"] = 'Produk tidak ditemukan atau tidak aktif.';

                continue;
            }

            $units[] = $unit;
            $lines[] = new CartLine(
                unitPrice: $unit->price,
                qty: (int) ($row['qty'] ?? 0),
                discountType: isset($row['discount_type']) ? DiscountType::from($row['discount_type']) : null,
                discountValue: (int) ($row['discount_value'] ?? 0),
            );
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $priced = $this->calculator->price($lines, $tax, $discountType, $discountValue);

        return new Cart($units, $lines, $priced, $tax, $discountType, $discountValue);
    }
}
