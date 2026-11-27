<?php

namespace App\Actions\Customers;

use App\Enums\CustomerPaymentStatus;
use App\Enums\LedgerEntryType;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Services\Payments\MidtransClient;
use App\Services\Payments\MidtransException;
use App\Services\Receivables\CustomerLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * QRIS state transitions for pelunasan kasbon (DESIGN.md §18), shared by the Midtrans
 * webhook, the payments page's polling, the cashier's cancel button and an owner closing the
 * shift. The sales machine (App\Actions\Sales\QrisPayments) is deliberately separate: that
 * one issues stock, this one takes debt down; only MidtransClient and its status mapping are
 * shared.
 *
 *   pending_payment ──settlement──▶ completed   (debt comes down here, exactly once)
 *   pending_payment ──expire/cancel/deny/failure──▶ cancelled
 *   cancelled ──settlement──▶ completed          (paid at the last second, if it still fits the debt)
 *
 * Lock order on settlement: customer → payment, the same customer-first order every other
 * debt change uses (shift → customer → sale → products).
 */
final class QrisCustomerPayments
{
    public const SETTLED = 'settled';

    public const CANCELLED = 'cancelled';

    public const UNCHANGED = 'unchanged';

    /** Paid, but the debt is smaller than the payment by now: left for the owner (refund at Midtrans). */
    public const OVER_BALANCE = 'over_balance';

    public function __construct(
        private readonly CustomerLedger $ledger,
        private readonly MidtransClient $midtrans,
    ) {}

    /** @return self::SETTLED|self::CANCELLED|self::UNCHANGED|self::OVER_BALANCE */
    public function apply(CustomerPayment $payment, string $transactionStatus, ?string $fraudStatus = null): string
    {
        return match (MidtransClient::outcome($transactionStatus, $fraudStatus)) {
            MidtransClient::SETTLE => $this->settle($payment),
            MidtransClient::CANCEL => $this->cancel($payment),
            default => self::UNCHANGED,
        };
    }

    /** @return self::SETTLED|self::UNCHANGED|self::OVER_BALANCE */
    public function settle(CustomerPayment $payment): string
    {
        return DB::transaction(function () use ($payment) {
            $customer = $this->ledger->lock(Customer::query()->findOrFail($payment->customer_id));

            /** @var CustomerPayment $locked */
            $locked = CustomerPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isQris()
                || ! in_array($locked->status, [CustomerPaymentStatus::PendingPayment, CustomerPaymentStatus::Cancelled], true)) {
                return self::UNCHANGED;
            }

            // Only possible for a late payment after a cancel: meanwhile the debt was paid in
            // cash or written off. There is no deposit balance (§17.4), so leave it to the owner.
            if ($locked->amount > $customer->balance) {
                Log::error('QRIS kasbon payment settled over the debt', [
                    'payment' => $locked->number,
                    'amount' => $locked->amount,
                    'balance' => $customer->balance,
                ]);

                return self::OVER_BALANCE;
            }

            $this->ledger->allocate($customer, LedgerEntryType::Payment, $locked->amount, $locked, $locked->user()->firstOrFail());

            $locked->forceFill([
                'status' => CustomerPaymentStatus::Completed,
                'completed_at' => now(),
            ])->save();

            return self::SETTLED;
        });
    }

    /** @return self::CANCELLED|self::UNCHANGED */
    public function cancel(CustomerPayment $payment): string
    {
        return DB::transaction(function () use ($payment) {
            /** @var CustomerPayment $locked */
            $locked = CustomerPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== CustomerPaymentStatus::PendingPayment) {
                return self::UNCHANGED;
            }

            $locked->forceFill(['status' => CustomerPaymentStatus::Cancelled])->save();

            return self::CANCELLED;
        });
    }

    /** Ask Midtrans directly (server to server) and apply the answer. */
    public function refresh(CustomerPayment $payment): string
    {
        try {
            $status = $this->midtrans->status($payment->number);
        } catch (MidtransException $e) {
            Log::info('Midtrans status check failed', ['payment' => $payment->number, 'error' => $e->getMessage()]);

            return self::UNCHANGED;
        } finally {
            $payment->forceFill(['payment_checked_at' => now()])->saveQuietly();
        }

        return $this->apply(
            $payment,
            (string) ($status['transaction_status'] ?? ''),
            isset($status['fraud_status']) ? (string) $status['fraud_status'] : null,
        );
    }

    /** The cashier (or an owner closing the shift) gives up on a QR. If it was paid meanwhile, keep it. */
    public function cancelByCashier(CustomerPayment $payment): string
    {
        $result = $this->refresh($payment);
        if ($result !== self::UNCHANGED) {
            return $result;
        }

        try {
            $this->midtrans->cancel($payment->number);
        } catch (MidtransException $e) {
            // Already expired or never reached Midtrans: either way it cannot be paid now.
            Log::info('Midtrans cancel refused', ['payment' => $payment->number, 'error' => $e->getMessage()]);
        }

        return $this->cancel($payment);
    }

    /**
     * Before refusing because of a waiting QR, check the ones that should have run out by now
     * (nobody polling, webhook not arriving), so a forgotten QR never holds a customer for long.
     * Call outside any transaction: it talks to Midtrans.
     */
    public function clearExpired(Customer $customer): void
    {
        CustomerPayment::query()
            ->where('customer_id', $customer->id)
            ->waiting()
            ->where('payment_expires_at', '<', now())
            ->get()
            ->each(fn (CustomerPayment $payment) => $this->refresh($payment));
    }

    /**
     * While a QRIS payment waits, nothing else may take this customer's debt down (DESIGN.md
     * §18.2), so the QR can never end up larger than the debt. Call with the customer locked.
     *
     * @throws ValidationException on `payment`
     */
    public static function ensureNoneWaiting(Customer $locked): void
    {
        $waiting = CustomerPayment::query()->where('customer_id', $locked->id)->waiting()->value('number');

        if ($waiting !== null) {
            throw ValidationException::withMessages([
                'payment' => "Masih ada QRIS pelunasan {$waiting} untuk {$locked->name} yang menunggu pembayaran. Selesaikan atau batalkan dulu.",
            ]);
        }
    }
}
