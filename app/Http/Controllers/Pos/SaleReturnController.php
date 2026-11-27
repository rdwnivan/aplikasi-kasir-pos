<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Sales\ProcessSaleReturn;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Sale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Inertia\Inertia;

class SaleReturnController extends Controller
{
    public function store(Request $request, Sale $sale, ProcessSaleReturn $process): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole(Role::Owner->value) || $sale->user_id === $user->id, 403);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.sale_item_id' => ['required', 'integer', 'distinct'],
            'items.*.qty' => ['required', 'integer', 'min:0', 'max:99999'],
            'owner_pin' => ['required', 'string', 'regex:/^\d{4,6}$/'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [
            'owner_pin.regex' => 'PIN terdiri dari 4–6 angka.',
        ], [
            'owner_pin' => 'PIN owner',
            'reason' => 'alasan',
            'items.*.qty' => 'jumlah retur',
        ]);

        $quantities = [];
        foreach ($data['items'] as $item) {
            $quantities[(int) $item['sale_item_id']] = (int) $item['qty'];
        }

        $return = $process->handle($user, $sale, $quantities, $data['owner_pin'], $data['reason'] ?? null);

        $cash = $return->cashRefund();
        $message = "Retur {$return->number} dicatat.";
        if ($return->credit_amount > 0) {
            $message .= ' Utang pelanggan berkurang Rp '.Number::format($return->credit_amount, locale: 'id').'.';
        }
        if ($cash > 0 || $return->credit_amount === 0) {
            $message .= ' Kembalikan uang tunai Rp '.Number::format($cash, locale: 'id').' ke pelanggan.';
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('pos.sales.show', $sale);
    }
}
