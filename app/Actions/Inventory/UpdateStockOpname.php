<?php

namespace App\Actions\Inventory;

use App\Models\Product;
use App\Models\StockOpname;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Replaces the counts on a draft stock opname. A posted opname is history and stays as is.
 */
final class UpdateStockOpname
{
    /**
     * @param  array<int, int>  $counts  product_id => counted qty in base units
     */
    public function handle(StockOpname $opname, array $counts, ?string $note = null): StockOpname
    {
        if ($counts === []) {
            throw ValidationException::withMessages(['items' => 'Minimal satu barang harus dihitung.']);
        }

        $known = Product::query()->whereIn('id', array_keys($counts))->pluck('id')->all();
        $missing = array_diff(array_keys($counts), $known);
        if ($missing !== []) {
            throw ValidationException::withMessages(['items' => 'Ada produk yang tidak ditemukan.']);
        }

        return DB::transaction(function () use ($opname, $counts, $note) {
            /** @var StockOpname $locked */
            $locked = StockOpname::query()->whereKey($opname->id)->lockForUpdate()->firstOrFail();

            if ($locked->isPosted()) {
                throw ValidationException::withMessages(['opname' => "Stok opname {$locked->number} sudah diposting dan tidak bisa diubah."]);
            }

            $locked->items()->delete();
            foreach ($counts as $productId => $qty) {
                $locked->items()->create(['product_id' => $productId, 'counted_qty' => $qty]);
            }
            $locked->update(['note' => $note]);

            return $locked->load('items');
        });
    }
}
