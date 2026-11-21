<?php

namespace App\Actions\Catalog;

use App\Models\GoodsReceiptItem;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\StockOpnameItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Removes a product. A product nobody has ever counted, bought or sold is deleted for real;
 * one with history is archived (soft-deleted and deactivated) so the ledger, receipts and
 * reports that point at it keep working.
 */
final class DeleteProduct
{
    public const DELETED = 'deleted';

    public const ARCHIVED = 'archived';

    /** @return self::DELETED|self::ARCHIVED */
    public function handle(Product $product): string
    {
        return DB::transaction(function () use ($product) {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if (! self::hasHistory($locked)) {
                $locked->forceDelete();

                return self::DELETED;
            }

            if ($locked->stock_on_hand !== 0) {
                throw ValidationException::withMessages([
                    'product' => "Stok {$locked->name} masih {$locked->stock_on_hand}. Nolkan dulu lewat stok opname, atau nonaktifkan produknya saja.",
                ]);
            }

            $locked->forceFill(['is_active' => false])->save();
            $locked->delete();

            return self::ARCHIVED;
        });
    }

    public static function hasHistory(Product $product): bool
    {
        return $product->stockMovements()->exists()
            || SaleItem::query()->where('product_id', $product->id)->exists()
            || GoodsReceiptItem::query()->where('product_id', $product->id)->exists()
            || StockOpnameItem::query()->where('product_id', $product->id)->exists();
    }
}
