<?php

namespace App\Queries;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * Active products at or below their minimum stock (out of stock counts too). Archived and
 * inactive products are not something to reorder, so they are left out.
 */
final class LowStock
{
    /** @return Builder<Product> */
    public static function query(): Builder
    {
        return Product::query()->where('is_active', true)->lowStock();
    }

    public static function count(): int
    {
        return self::query()->count();
    }

    /**
     * Emptiest first: out of stock, then the furthest below minimum.
     *
     * @return list<array{id: int, sku: string, name: string, baseUnit: string, stock: int, minStock: int}>
     */
    public static function list(int $limit = 10): array
    {
        return array_values(self::query()
            ->with('baseUnit')
            ->orderBy('stock_on_hand')
            ->orderByDesc('min_stock')
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'baseUnit' => $p->baseUnit->name,
                'stock' => $p->stock_on_hand,
                'minStock' => $p->min_stock,
            ])
            ->all());
    }
}
