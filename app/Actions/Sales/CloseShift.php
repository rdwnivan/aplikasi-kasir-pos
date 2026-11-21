<?php

namespace App\Actions\Sales;

use App\Enums\SaleStatus;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes the drawer: expected = float + cash sales − cash refunds, difference = counted − expected
 * (negative means cash is missing). Held carts must be finished or discarded first.
 */
final class CloseShift
{
    public function handle(Shift $shift, int $countedCash, ?string $note = null): Shift
    {
        if ($countedCash < 0) {
            throw ValidationException::withMessages(['counted_cash' => 'Jumlah uang tidak boleh negatif.']);
        }

        return DB::transaction(function () use ($shift, $countedCash, $note) {
            /** @var Shift $locked */
            $locked = Shift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['shift' => 'Shift ini sudah ditutup.']);
            }

            if ($locked->sales()->where('status', SaleStatus::Held)->exists()) {
                throw ValidationException::withMessages(['shift' => 'Masih ada transaksi yang ditahan. Selesaikan atau hapus dulu.']);
            }

            $expected = $locked->expectedCash();

            $locked->forceFill([
                'closed_at' => now(),
                'expected_cash' => $expected,
                'counted_cash' => $countedCash,
                'cash_difference' => $countedCash - $expected,
                'note' => $note ?? $locked->note,
            ])->save();

            return $locked;
        });
    }
}
