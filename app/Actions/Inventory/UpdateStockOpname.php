<?php

namespace App\Actions\Inventory;

use App\Models\StockOpname;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Replaces the counts on a draft stock opname. A posted opname is history and stays as is.
 */
final class UpdateStockOpname
{
    use SavesOpnameCounts;

    /**
     * @param  array<int, int>  $counts  product_id => counted qty in base units
     * @param  array<int, list<array<string, mixed>>>  $batches  per-batch counts of products that track expiry
     */
    public function handle(StockOpname $opname, array $counts, ?string $note = null, array $batches = []): StockOpname
    {
        [$counts, $batches] = $this->checkCounts($counts, $batches);

        return DB::transaction(function () use ($opname, $counts, $batches, $note) {
            /** @var StockOpname $locked */
            $locked = StockOpname::query()->whereKey($opname->id)->lockForUpdate()->firstOrFail();

            if ($locked->isPosted()) {
                throw ValidationException::withMessages(['opname' => "Stok opname {$locked->number} sudah diposting dan tidak bisa diubah."]);
            }

            $locked->items()->delete(); // batch counts go with them (cascade)
            $this->writeCounts($locked, $counts, $batches);
            $locked->update(['note' => $note]);

            return $locked->load('items');
        });
    }
}
