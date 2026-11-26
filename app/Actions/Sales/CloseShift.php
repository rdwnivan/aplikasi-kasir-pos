<?php

namespace App\Actions\Sales;

use App\Enums\SaleStatus;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes the drawer: expected = float + cash sales − cash refunds, difference = counted − expected
 * (negative means cash is missing). Held carts must be finished or discarded first.
 *
 * This is the cashier closing their own shift; an owner closing someone else's goes through
 * CloseShiftForUser, which clears waiting QRIS and held carts first and shares closeLocked().
 */
final class CloseShift
{
    public function handle(Shift $shift, int $countedCash, ?string $note = null, ?User $closedBy = null): Shift
    {
        self::ensureCountable($countedCash);

        return DB::transaction(function () use ($shift, $countedCash, $note, $closedBy) {
            $locked = self::lockOpen($shift);

            if ($locked->sales()->where('status', SaleStatus::PendingPayment)->exists()) {
                throw ValidationException::withMessages(['shift' => 'Masih ada QRIS yang menunggu pembayaran. Selesaikan atau batalkan dulu.']);
            }

            if ($locked->sales()->where('status', SaleStatus::Held)->exists()) {
                throw ValidationException::withMessages(['shift' => 'Masih ada transaksi yang ditahan. Selesaikan atau hapus dulu.']);
            }

            return self::closeLocked($locked, $countedCash, $closedBy->id ?? $locked->user_id, note: $note);
        });
    }

    public static function ensureCountable(int $countedCash): void
    {
        if ($countedCash < 0) {
            throw ValidationException::withMessages(['counted_cash' => 'Jumlah uang tidak boleh negatif.']);
        }
    }

    /**
     * Lock the shift row and make sure it is still open. Sales, holds, QRIS and returns take
     * the same lock (ResolvesOpenShift::lockOpenShift), so none can land on a closing shift.
     * Must be called inside a transaction.
     */
    public static function lockOpen(Shift $shift): Shift
    {
        /** @var Shift $locked */
        $locked = Shift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isOpen()) {
            throw ValidationException::withMessages(['shift' => 'Shift ini sudah ditutup.']);
        }

        return $locked;
    }

    /** Write the closing figures on a shift locked by lockOpen(). */
    public static function closeLocked(Shift $locked, int $countedCash, int $closedBy, ?string $note = null, ?string $reason = null): Shift
    {
        $expected = $locked->expectedCash();

        $locked->forceFill([
            'closed_at' => now(),
            'closed_by' => $closedBy,
            'close_reason' => $reason,
            'expected_cash' => $expected,
            'counted_cash' => $countedCash,
            'cash_difference' => $countedCash - $expected,
            'note' => $note ?? $locked->note,
        ])->save();

        return $locked;
    }
}
