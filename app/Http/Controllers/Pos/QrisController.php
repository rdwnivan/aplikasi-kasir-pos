<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Sales\QrisPayments;
use App\Actions\Sales\StartQrisPayment;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\QrisCheckoutRequest;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class QrisController extends Controller
{
    /** Seconds between live checks against Midtrans while the till polls. */
    private const CHECK_EVERY = 3;

    public function store(QrisCheckoutRequest $request, StartQrisPayment $start): RedirectResponse
    {
        $held = $request->validated('held_sale_id')
            ? Sale::query()->where('status', SaleStatus::Held)->find((int) $request->validated('held_sale_id'))
            : null;

        $start->handle(
            $request->user(),
            $request->cartItems(),
            $request->cartDiscountType(),
            $request->cartDiscountValue(),
            $held,
        );

        // The till picks the pending QRIS up from its props and shows the QR.
        return to_route('pos.index');
    }

    /** Polled by the till every few seconds while the QR is on screen. */
    public function show(Request $request, Sale $sale, QrisPayments $payments): JsonResponse
    {
        $this->authorizeSale($request, $sale);

        if ($sale->status === SaleStatus::PendingPayment) {
            $due = $sale->payment_checked_at === null
                || $sale->payment_checked_at->diffInSeconds(now()) >= self::CHECK_EVERY;

            if ($due) {
                $payments->refresh($sale);
                $sale->refresh();
            }
        }

        if ($sale->status === SaleStatus::Completed) {
            $request->session()->put('last_sale_id', $sale->id);
        }

        return response()->json([
            'id' => $sale->id,
            'number' => $sale->number,
            'status' => $sale->status->value,
            'expiresAt' => $sale->payment_expires_at?->toIso8601String(),
        ]);
    }

    public function cancel(Request $request, Sale $sale, QrisPayments $payments): RedirectResponse
    {
        $this->authorizeSale($request, $sale);

        $result = $payments->cancelByCashier($sale);

        if ($result === QrisPayments::SETTLED) {
            $request->session()->put('last_sale_id', $sale->id);
            Inertia::flash('toast', ['type' => 'success', 'message' => "Ternyata {$sale->number} sudah dibayar. Transaksi selesai."]);
        } else {
            Inertia::flash('toast', ['type' => 'info', 'message' => "QRIS {$sale->number} dibatalkan."]);
        }

        return to_route('pos.index');
    }

    private function authorizeSale(Request $request, Sale $sale): void
    {
        abort_unless($sale->payment_method === PaymentMethod::Qris, 404);
        abort_unless($sale->user_id === $request->user()->id, 403);
    }
}
