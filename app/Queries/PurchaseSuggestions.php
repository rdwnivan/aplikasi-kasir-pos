<?php

namespace App\Queries;

use App\Models\GoodsReceiptItem;
use App\Models\Product;
use App\Models\ProductUnit;

/**
 * What the purchase order form pre-fills: the last price paid per product unit, and a draft
 * built from low stock (docs/DESIGN.md §10.5).
 */
final class PurchaseSuggestions
{
    /**
     * Price on the most recent receipt line per product and unit.
     *
     * @return array<string, int> "productId-unitId" => Rupiah
     */
    public static function lastPrices(): array
    {
        $latest = GoodsReceiptItem::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('product_id', 'unit_id');

        return GoodsReceiptItem::query()
            ->whereIn('id', $latest)
            ->get(['product_id', 'unit_id', 'unit_price'])
            ->mapWithKeys(fn (GoodsReceiptItem $i) => ["{$i->product_id}-{$i->unit_id}" => $i->unit_price])
            ->all();
    }

    /**
     * One line per low-stock product, alphabetical: in the unit it was last bought in (else
     * its base unit), enough to bring stock up to twice the minimum, at the last price paid.
     *
     * @return list<array{product_id: int, unit_id: int, qty: int, unit_price: int}>
     */
    public static function fromLowStock(): array
    {
        $prices = self::lastPrices();
        $lastUnits = GoodsReceiptItem::query()
            ->whereIn('id', GoodsReceiptItem::query()->selectRaw('MAX(id)')->groupBy('product_id'))
            ->pluck('unit_id', 'product_id');

        return array_values(LowStock::query()
            ->with('units')
            ->orderBy('name')
            ->get()
            ->map(function (Product $product) use ($prices, $lastUnits) {
                $units = $product->units;
                $unit = $units->firstWhere('unit_id', $lastUnits[$product->id] ?? null)
                    ?? $units->firstWhere('unit_id', $product->base_unit_id)
                    ?? $units->sortBy('conversion')->first();

                if (! $unit instanceof ProductUnit) {
                    return null;
                }

                $shortfall = 2 * $product->min_stock - $product->stock_on_hand;

                return [
                    'product_id' => $product->id,
                    'unit_id' => $unit->unit_id,
                    'qty' => max(1, (int) ceil($shortfall / max(1, $unit->conversion))),
                    'unit_price' => $prices["{$product->id}-{$unit->unit_id}"] ?? 0,
                ];
            })
            ->filter()
            ->all());
    }
}
