<?php

namespace App\Services\Sales;

use App\Enums\DiscountType;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\SaleItem;

/**
 * A priced cart, ready to be written as a held or completed sale.
 */
final readonly class Cart
{
    /**
     * @param  list<ProductUnit>  $units  one per line, with product and unit loaded
     * @param  list<CartLine>  $lines
     */
    public function __construct(
        public array $units,
        public array $lines,
        public PricedCart $priced,
        public TaxSettings $tax,
        public ?DiscountType $discountType,
        public int $discountValue,
    ) {}

    /** @return array<string, mixed> Sale columns for the amounts and tax snapshot. */
    public function saleAttributes(): array
    {
        return [
            'subtotal' => $this->priced->subtotal,
            'discount_type' => $this->priced->discount > 0 ? $this->discountType : null,
            'discount_value' => $this->priced->discount > 0 ? $this->discountValue : null,
            'discount_amount' => $this->priced->discount,
            'prices_include_tax' => $this->tax->pricesIncludeTax,
            'tax_rate' => $this->tax->enabled ? $this->tax->rate : '0',
            'taxable_amount' => $this->priced->taxableAmount,
            'dpp_amount' => $this->priced->dppAmount,
            'tax_amount' => $this->priced->taxAmount,
            'total' => $this->priced->total,
        ];
    }

    /** @return list<SaleItem> in cart order */
    public function writeItems(Sale $sale): array
    {
        $items = [];

        foreach ($this->units as $i => $unit) {
            $line = $this->lines[$i];
            $priced = $this->priced->lines[$i];

            $items[] = $sale->items()->create([
                'product_id' => $unit->product_id,
                'product_unit_id' => $unit->id,
                'product_name' => $unit->product->name,
                'unit_name' => $unit->unit->name,
                'qty' => $line->qty,
                'conversion' => $unit->conversion,
                'unit_price' => $line->unitPrice,
                'discount_type' => $priced->discount > 0 ? $line->discountType : null,
                'discount_value' => $priced->discount > 0 ? $line->discountValue : null,
                'discount_amount' => $priced->discount,
                'line_total' => $priced->total,
            ]);
        }

        return $items;
    }
}
