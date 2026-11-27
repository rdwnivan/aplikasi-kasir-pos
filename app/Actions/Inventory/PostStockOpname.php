<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts a draft count: for each line, the system quantity is read under the product lock at
 * posting time (not when the draft was made), and the difference becomes an `opname` movement.
 * Posting twice is refused.
 */
final class PostStockOpname
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function handle(StockOpname $opname, User $user): StockOpname
    {
        return DB::transaction(function () use ($opname, $user) {
            /** @var StockOpname $locked */
            $locked = StockOpname::query()->whereKey($opname->id)->lockForUpdate()->firstOrFail();

            if ($locked->isPosted()) {
                throw ValidationException::withMessages(['opname' => "Stok opname {$locked->number} sudah diposting."]);
            }

            foreach ($locked->items()->with(['product', 'batches.batch'])->orderBy('product_id')->get() as $item) {
                if ($item->product->tracks_expiry) {
                    $this->postBatches($locked, $item, $user);

                    continue;
                }

                $movement = $this->ledger->adjustTo(
                    $item->product,
                    $item->counted_qty,
                    StockMovementType::Opname,
                    reference: $locked,
                    user: $user,
                );

                $difference = $movement === null ? 0 : $movement->qty;

                $item->forceFill([
                    'system_qty' => $item->counted_qty - $difference,
                    'difference' => $difference,
                ])->save();
            }

            $locked->forceFill([
                'status' => StockOpname::STATUS_POSTED,
                'posted_at' => now(),
            ])->save();

            return $locked->load('items');
        });
    }

    /**
     * A product that tracks expiry: each counted batch is set to its count (batches left off
     * the count are not touched), one opname movement per batch that differs (DESIGN.md §19).
     */
    private function postBatches(StockOpname $opname, StockOpnameItem $item, User $user): void
    {
        if ($item->batches->isEmpty()) {
            throw ValidationException::withMessages([
                'opname' => "{$item->product->name} kini melacak tanggal kedaluwarsa: ubah draft ini dan hitung per tanggal.",
            ]);
        }

        $systemTotal = 0;
        $differenceTotal = 0;

        foreach ($item->batches->sortBy('id') as $line) {
            $movement = $this->ledger->adjustBatchTo(
                $item->product,
                $line->batch ?? ['expires_on' => $line->expires_on?->toDateString(), 'code' => $line->code],
                $line->counted_qty,
                StockMovementType::Opname,
                reference: $opname,
                user: $user,
            );

            $difference = $movement === null ? 0 : $movement->qty;
            $line->forceFill([
                'stock_batch_id' => $line->stock_batch_id ?? $movement?->stock_batch_id,
                'system_qty' => $line->counted_qty - $difference,
                'difference' => $difference,
            ])->save();

            $systemTotal += $line->counted_qty - $difference;
            $differenceTotal += $difference;
        }

        $item->forceFill(['system_qty' => $systemTotal, 'difference' => $differenceTotal])->save();
    }
}
