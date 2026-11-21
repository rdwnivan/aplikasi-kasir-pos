<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Sales\DiscardHeldSale;
use App\Actions\Sales\HoldSale;
use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\HoldSaleRequest;
use App\Models\Sale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HeldSaleController extends Controller
{
    public function store(HoldSaleRequest $request, HoldSale $hold): RedirectResponse
    {
        $replacing = $request->validated('held_sale_id')
            ? Sale::query()->where('status', SaleStatus::Held)->find((int) $request->validated('held_sale_id'))
            : null;

        $hold->handle(
            $request->user(),
            $request->cartItems(),
            $request->validated('label'),
            $request->cartDiscountType(),
            $request->cartDiscountValue(),
            $replacing,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Transaksi ditahan.']);

        return to_route('pos.index');
    }

    public function destroy(Request $request, Sale $sale, DiscardHeldSale $discard): RedirectResponse
    {
        $discard->handle($sale, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Transaksi yang ditahan dihapus.']);

        return to_route('pos.index');
    }
}
