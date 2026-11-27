<?php

namespace App\Actions\Inventory;

use App\Models\StockOpname;
use App\Models\User;
use App\Services\Documents\DocumentNumber;
use Illuminate\Support\Facades\DB;

/**
 * Saves a draft stock count. Nothing touches stock until the draft is posted, so staff can
 * count over several hours and fix typos first.
 */
final class CreateStockOpname
{
    use SavesOpnameCounts;

    public function __construct(private readonly DocumentNumber $numbers) {}

    /**
     * @param  array<int, int>  $counts  product_id => counted qty in base units
     * @param  array<int, list<array<string, mixed>>>  $batches  per-batch counts of products that track expiry
     */
    public function handle(User $user, array $counts, ?string $note = null, array $batches = []): StockOpname
    {
        [$counts, $batches] = $this->checkCounts($counts, $batches);

        return DB::transaction(function () use ($user, $counts, $batches, $note) {
            $opname = StockOpname::create([
                'number' => $this->numbers->next(DocumentNumber::STOCK_OPNAME),
                'user_id' => $user->id,
                'status' => StockOpname::STATUS_DRAFT,
                'note' => $note,
            ]);

            $this->writeCounts($opname, $counts, $batches);

            return $opname->load('items');
        });
    }
}
