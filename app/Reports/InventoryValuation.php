<?php

namespace App\Reports;

use App\Models\Product;
use App\Support\Decimal;

/**
 * What the stock on the shelf is worth right now: stock_on_hand × moving-average HPP, per
 * product and per category. A snapshot of the ledger cache, not a historical figure.
 */
final class InventoryValuation
{
    /**
     * @return array{rows: list<array<string, mixed>>, categories: list<array{name: string, value: int, products: int}>, total: int, units: int}
     */
    public static function build(): array
    {
        $rows = array_values(Product::query()
            ->where('is_active', true)
            ->with(['category', 'baseUnit'])
            ->orderBy('name')
            ->get()
            ->map(fn (Product $p) => [
                'productId' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'category' => $p->category->name ?? 'Tanpa kategori',
                'baseUnit' => $p->baseUnit->name,
                'stock' => $p->stock_on_hand,
                'avgCost' => (float) $p->avg_cost,
                'value' => (int) Decimal::round(Decimal::mul((string) $p->stock_on_hand, (string) $p->avg_cost), 0),
            ])
            ->sortByDesc('value')
            ->all());

        $categories = [];
        foreach ($rows as $row) {
            $categories[$row['category']] ??= ['name' => $row['category'], 'value' => 0, 'products' => 0];
            $categories[$row['category']]['value'] += $row['value'];
            $categories[$row['category']]['products']++;
        }
        usort($categories, fn (array $a, array $b) => $b['value'] <=> $a['value']);

        return [
            'rows' => $rows,
            'categories' => $categories,
            'total' => array_sum(array_column($rows, 'value')),
            'units' => count($rows),
        ];
    }
}
