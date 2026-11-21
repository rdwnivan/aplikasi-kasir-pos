<?php

namespace App\Actions\Sales;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Validation\ValidationException;

trait ResolvesOpenShift
{
    private function openShiftOf(User $cashier): Shift
    {
        return Shift::query()->open()->where('user_id', $cashier->id)->first()
            ?? throw ValidationException::withMessages(['shift' => 'Buka shift dulu sebelum bertransaksi.']);
    }
}
