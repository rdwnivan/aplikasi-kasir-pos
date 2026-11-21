<?php

namespace App\Actions\Sales;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OpenShift
{
    public function handle(User $cashier, int $openingCash, ?string $note = null): Shift
    {
        if ($openingCash < 0) {
            throw ValidationException::withMessages(['opening_cash' => 'Modal awal tidak boleh negatif.']);
        }

        return DB::transaction(function () use ($cashier, $openingCash, $note) {
            // Serialise per cashier so a double-click cannot open two shifts.
            User::query()->whereKey($cashier->id)->lockForUpdate()->firstOrFail();

            if (Shift::query()->open()->where('user_id', $cashier->id)->exists()) {
                throw ValidationException::withMessages(['shift' => 'Masih ada shift yang belum ditutup.']);
            }

            return Shift::create([
                'user_id' => $cashier->id,
                'opened_at' => now(),
                'opening_cash' => $openingCash,
                'note' => $note,
            ]);
        });
    }
}
