<?php

namespace App\Actions\Sales;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An owner closes a shift someone else left open (DESIGN.md §13), with the drawer they
 * counted and a reason. Unlike the cashier's own close, nothing left on the shift blocks it:
 *
 * - each QRIS still waiting is checked with Midtrans first (outside any transaction): paid
 *   ones are booked and count, the rest are cancelled at Midtrans; anything still waiting
 *   afterwards (Midtrans unreachable) is cancelled here;
 * - held carts are discarded (they reserve no stock).
 */
final class CloseShiftForUser
{
    public function __construct(private readonly QrisPayments $payments) {}

    public function handle(User $owner, Shift $shift, int $countedCash, string $reason): Shift
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['reason' => 'Alasan wajib diisi (maks. 255 karakter).']);
        }
        CloseShift::ensureCountable($countedCash);

        if ($shift->user_id === $owner->id) {
            throw ValidationException::withMessages(['shift' => 'Tutup shift Anda sendiri dari layar kasir.']);
        }
        if (! $shift->isOpen()) {
            throw ValidationException::withMessages(['shift' => 'Shift ini sudah ditutup.']);
        }

        $shift->sales()->where('status', SaleStatus::PendingPayment)->get()
            ->each(fn (Sale $sale) => $this->payments->cancelByCashier($sale));

        return DB::transaction(function () use ($owner, $shift, $countedCash, $reason) {
            $locked = CloseShift::lockOpen($shift);

            $locked->sales()->where('status', SaleStatus::Held)->lockForUpdate()->get()
                ->each(fn (Sale $held) => $held->delete());

            $locked->sales()->where('status', SaleStatus::PendingPayment)->lockForUpdate()->get()
                ->each(fn (Sale $sale) => $sale->forceFill(['status' => SaleStatus::Cancelled])->save());

            return CloseShift::closeLocked($locked, $countedCash, $owner->id, reason: $reason);
        });
    }
}
