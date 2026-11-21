<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Sales\CompleteSale;
use App\Enums\SaleStatus;
use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\CheckoutRequest;
use App\Models\Sale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    public function store(CheckoutRequest $request, CompleteSale $complete): RedirectResponse
    {
        $held = $request->validated('held_sale_id')
            ? Sale::query()->where('status', SaleStatus::Held)->find((int) $request->validated('held_sale_id'))
            : null;

        try {
            $sale = $complete->handle(
                $request->user(),
                $request->cartItems(),
                (int) $request->validated('paid_amount'),
                $request->cartDiscountType(),
                $request->cartDiscountValue(),
                $held,
            );
        } catch (InsufficientStockException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        return to_route('pos.index')->with('last_sale_id', $sale->id);
    }
}
