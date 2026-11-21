<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Models\StockOpname;
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

            foreach ($locked->items()->with('product')->orderBy('product_id')->get() as $item) {
                $movement = $this->ledger->adjustTo(
                    $item->product,
                    $item->counted_qty,
                    StockMovementType::Opname,
                    reference: $locked,
                    user: $user,
                );

                $difference = $movement?->qty ?? 0;

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
}
