<?php

namespace App\Http\Controllers\Inventory;

use App\Models\Product;
use App\Models\ProductUnit;

/**
 * The product list the stock forms pick from, sent whole to the client for instant search
 * and barcode scans (same trade-off as the till's catalogue).
 */
final class ProductOptions
{
    /** @return list<array<string, mixed>> */
    public static function active(): array
    {
        return array_values(Product::query()
            ->where('is_active', true)
            ->with(['baseUnit', 'category', 'units' => fn ($q) => $q->with('unit')->orderBy('conversion')])
            ->orderBy('name')
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'category' => $p->category?->name,
                'categoryId' => $p->category_id,
                'baseUnit' => $p->baseUnit->name,
                'stock' => $p->stock_on_hand,
                'avgCost' => (float) $p->avg_cost,
                'units' => $p->units->map(fn (ProductUnit $u) => [
                    'unitId' => $u->unit_id,
                    'name' => $u->unit->name,
                    'conversion' => $u->conversion,
                    'barcode' => $u->barcode,
                ])->all(),
            ])
            ->all());
    }
}
