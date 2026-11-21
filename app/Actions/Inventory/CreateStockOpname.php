<?php

namespace App\Actions\Inventory;

use App\Models\Product;
use App\Models\StockOpname;
use App\Models\User;
use App\Services\Documents\DocumentNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves a draft stock count. Nothing touches stock until the draft is posted, so staff can
 * count over several hours and fix typos first.
 */
final class CreateStockOpname
{
    public function __construct(private readonly DocumentNumber $numbers) {}

    /**
     * @param  array<int, int>  $counts  product_id => counted qty in base units
     */
    public function handle(User $user, array $counts, ?string $note = null): StockOpname
    {
        if ($counts === []) {
            throw ValidationException::withMessages(['items' => 'Minimal satu barang harus dihitung.']);
        }

        $errors = [];
        foreach ($counts as $productId => $qty) {
            if ($qty < 0) {
                $errors["items.$productId"] = 'Hasil hitung tidak boleh negatif.';
            }
        }
        $known = Product::query()->whereIn('id', array_keys($counts))->pluck('id')->all();
        foreach (array_diff(array_keys($counts), $known) as $missing) {
            $errors["items.$missing"] = 'Produk tidak ditemukan.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($user, $counts, $note) {
            $opname = StockOpname::create([
                'number' => $this->numbers->next(DocumentNumber::STOCK_OPNAME),
                'user_id' => $user->id,
                'status' => StockOpname::STATUS_DRAFT,
                'note' => $note,
            ]);

            foreach ($counts as $productId => $qty) {
                $opname->items()->create(['product_id' => $productId, 'counted_qty' => $qty]);
            }

            return $opname->load('items');
        });
    }
}
