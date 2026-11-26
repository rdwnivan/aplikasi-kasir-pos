<?php

namespace App\Actions\Sales;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Validation\ValidationException;

trait ResolvesOpenShift
{
    private const NO_OPEN_SHIFT = 'Buka shift dulu sebelum bertransaksi.';

    /** A quick check before any work; repeat it with lockOpenShift() inside the transaction. */
    private function openShiftOf(User $cashier): Shift
    {
        return Shift::query()->open()->where('user_id', $cashier->id)->first()
            ?? throw ValidationException::withMessages(['shift' => self::NO_OPEN_SHIFT]);
    }

    /**
     * Lock the shift row and check it is still open, inside the transaction that writes to it.
     * An owner may close the shift meanwhile (DESIGN.md §13); CloseShift takes the same lock
     * first, so the two run one after the other and nothing lands on a closed shift.
     * Lock order everywhere: shift → sale → products.
     */
    private function lockOpenShift(Shift $shift): Shift
    {
        /** @var Shift $locked */
        $locked = Shift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isOpen()) {
            throw ValidationException::withMessages(['shift' => self::NO_OPEN_SHIFT]);
        }

        return $locked;
    }
}
