<?php

namespace App\Actions\Sales;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DiscardHeldSale
{
    public function handle(Sale $sale, User $cashier): void
    {
        DB::transaction(fn () => self::deleteLocked($sale, $cashier));
    }

    /** Must be called inside a transaction. */
    public static function deleteLocked(Sale $sale, User $cashier): void
    {
        $locked = Sale::query()->whereKey($sale->id)->lockForUpdate()->first();

        if ($locked === null || $locked->status !== SaleStatus::Held) {
            throw ValidationException::withMessages(['sale' => 'Transaksi yang ditahan sudah tidak ada.']);
        }
        if ($locked->user_id !== $cashier->id) {
            throw ValidationException::withMessages(['sale' => 'Transaksi ini ditahan oleh kasir lain.']);
        }

        $locked->delete();
    }
}
