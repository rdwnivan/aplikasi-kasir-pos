<?php

namespace App\Http\Controllers\Reports;

use App\Actions\Sales\CloseShiftForUser;
use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pos\PosController;
use App\Http\Controllers\Pos\ShiftController;
use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Models\Shift;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** An owner closes a shift someone else left open (DESIGN.md §13). */
class ShiftCloseController extends Controller
{
    public function edit(Request $request, Shift $shift): Response|RedirectResponse
    {
        if ($redirect = $this->elsewhere($request, $shift)) {
            return $redirect;
        }

        $shift->load('user');
        $lastSale = $shift->sales()->where('status', SaleStatus::Completed)->latest('completed_at')->first();

        return Inertia::render('reports/close-shift', [
            'shift' => PosController::shiftSummary($shift),
            'cashier' => $shift->user->name,
            'heldCount' => $shift->sales()->where('status', SaleStatus::Held)->count(),
            'pendingQris' => $shift->sales()
                ->where('status', SaleStatus::PendingPayment)
                ->orderBy('id')
                ->get()
                ->map(fn (Sale $sale) => [
                    'number' => $sale->number,
                    'total' => $sale->total,
                    'cashAmount' => $sale->cashPart(),
                ])->all(),
            // QRIS pelunasan waiting on this shift: checked with Midtrans, then settled or cancelled (§18).
            'pendingPayments' => $shift->customerPayments()
                ->waiting()
                ->with('customer')
                ->orderBy('id')
                ->get()
                ->map(fn (CustomerPayment $payment) => [
                    'number' => $payment->number,
                    'customer' => $payment->customer->name,
                    'amount' => $payment->amount,
                ])->all(),
            // A hint that the cashier may still be serving customers on this shift.
            'lastSaleAt' => $lastSale?->completed_at?->toIso8601String(),
        ]);
    }

    public function update(Request $request, Shift $shift, CloseShiftForUser $close): RedirectResponse
    {
        if ($redirect = $this->elsewhere($request, $shift)) {
            return $redirect;
        }

        $data = $request->validate([
            'counted_cash' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'reason' => ['required', 'string', 'max:255'],
        ], attributes: ['counted_cash' => 'uang dihitung', 'reason' => 'alasan']);

        $closed = $close->handle($request->user(), $shift, (int) $data['counted_cash'], $data['reason']);

        ShiftController::flashClosed($closed, "Shift {$shift->user->name} ditutup.");

        return to_route('reports.shifts');
    }

    /** The owner's own shift is closed from the till; a closed one has nothing left to do. */
    private function elsewhere(Request $request, Shift $shift): ?RedirectResponse
    {
        if ($shift->user_id === $request->user()->id) {
            return to_route('pos.shift.edit');
        }

        if (! $shift->isOpen()) {
            Inertia::flash('toast', ['type' => 'info', 'message' => 'Shift ini sudah ditutup.']);

            return to_route('reports.shifts');
        }

        return null;
    }
}
