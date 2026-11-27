<?php

namespace App\Http\Controllers\Inventory;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockBatch;

/**
 * The product list the stock forms pick from, sent whole to the client for instant search
 * and barcode scans (same trade-off as the till's catalogue).
 */
final class ProductOptions
{
    /** @return list<array<string, mixed>> */
    public static function active(): array
    {
        // Open batches, first expired first, for counting per batch (DESIGN.md §19).
        $batches = StockBatch::query()->where('qty_on_hand', '>', 0)->fefo()->get()->groupBy('product_id');

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
                'tracksExpiry' => $p->tracks_expiry,
                'batches' => $p->tracks_expiry ? $batches->get($p->id, collect())->map(fn (StockBatch $b) => [
                    'id' => $b->id,
                    'expiresOn' => $b->expires_on?->toDateString(),
                    'code' => $b->code,
                    'qty' => $b->qty_on_hand,
                    'status' => $b->status(),
                ])->all() : [],
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
