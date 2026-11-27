<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Customers\QrisCustomerPayments;
use App\Actions\Customers\StartQrisCustomerPayment;
use App\Enums\CustomerPaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Pelunasan kasbon by QRIS at the till (DESIGN.md §18): start, poll, cancel. */
class CustomerPaymentQrisController extends Controller
{
    /** Seconds between live checks against Midtrans while the page polls. */
    private const CHECK_EVERY = 3;

    public function store(Request $request, StartQrisCustomerPayment $start): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'amount.min' => 'Jumlah pelunasan minimal Rp 1.',
        ], attributes: ['customer_id' => 'pelanggan', 'amount' => 'jumlah']);

        $customer = Customer::query()->findOrFail((int) $data['customer_id']);
        $start->handle($request->user(), $customer, (int) $data['amount'], $data['note'] ?? null);

        // The page picks the waiting QR up from its props and shows it.
        return to_route('pos.payments.create', ['pelanggan' => $customer->id]);
    }

    /** Polled by the payments page every few seconds while the QR is on screen. */
    public function show(Request $request, CustomerPayment $payment, QrisCustomerPayments $payments): JsonResponse
    {
        $this->authorizePayment($request, $payment);

        if ($payment->status === CustomerPaymentStatus::PendingPayment) {
            $due = $payment->payment_checked_at === null
                || $payment->payment_checked_at->diffInSeconds(now()) >= self::CHECK_EVERY;

            if ($due) {
                $payments->refresh($payment);
                $payment->refresh();
            }
        }

        // Flashed for the page reload that follows, which shows the success dialog once.
        if ($payment->status === CustomerPaymentStatus::Completed) {
            $request->session()->flash('last_payment_id', $payment->id);
        }

        return response()->json([
            'id' => $payment->id,
            'number' => $payment->number,
            'status' => $payment->status->value,
            'expiresAt' => $payment->payment_expires_at?->toIso8601String(),
        ]);
    }

    public function cancel(Request $request, CustomerPayment $payment, QrisCustomerPayments $payments): RedirectResponse
    {
        $this->authorizePayment($request, $payment);

        $result = $payments->cancelByCashier($payment);

        if ($result === QrisCustomerPayments::SETTLED) {
            $request->session()->flash('last_payment_id', $payment->id);
            Inertia::flash('toast', ['type' => 'success', 'message' => "Ternyata {$payment->number} sudah dibayar. Pelunasan diterima."]);
        } else {
            Inertia::flash('toast', ['type' => 'info', 'message' => "QRIS {$payment->number} dibatalkan."]);
        }

        return to_route('pos.payments.create', ['pelanggan' => $payment->customer_id]);
    }

    private function authorizePayment(Request $request, CustomerPayment $payment): void
    {
        abort_unless($payment->isQris(), 404);
        abort_unless($payment->user_id === $request->user()->id, 403);
    }
}
