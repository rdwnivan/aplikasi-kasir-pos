<?php

namespace App\Actions\Customers;

use App\Actions\Sales\ResolvesOpenShift;
use App\Enums\CustomerPaymentStatus;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\User;
use App\Services\Documents\DocumentNumber;
use App\Services\Payments\MidtransClient;
use App\Services\Payments\MidtransException;
use App\Services\Receivables\CustomerLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pelunasan by QRIS, step one (DESIGN.md §18): write the payment as pending_payment with its
 * PAY-… number (also the Midtrans order_id) and ask Midtrans for a QR. No ledger row yet;
 * the debt comes down when the payment settles (QrisCustomerPayments::settle).
 *
 * Lock order: shift → customer, then the waiting check and the insert under both, so no
 * second QR, cash payment, write-off or return can slip in for the same customer.
 */
final class StartQrisCustomerPayment
{
    use ResolvesOpenShift;

    public function __construct(
        private readonly DocumentNumber $numbers,
        private readonly MidtransClient $midtrans,
        private readonly QrisCustomerPayments $payments,
        private readonly CustomerLedger $ledger,
    ) {}

    public function handle(User $actor, Customer $customer, int $amount, ?string $note = null): CustomerPayment
    {
        ReceiveCustomerPayment::ensurePositive($amount);

        if (! $this->midtrans->isConfigured()) {
            throw ValidationException::withMessages(['payment' => 'QRIS belum aktif: isi MIDTRANS_SERVER_KEY di file .env.']);
        }

        $shift = $this->openShiftOf($actor);
        $this->payments->clearExpired($customer);

        $payment = DB::transaction(function () use ($actor, $customer, $amount, $note, $shift) {
            $this->lockOpenShift($shift);

            if ($shift->customerPayments()->waiting()->exists()) {
                throw ValidationException::withMessages(['payment' => 'Masih ada QRIS pelunasan yang menunggu pembayaran. Selesaikan atau batalkan dulu.']);
            }

            $locked = $this->ledger->lock($customer);
            QrisCustomerPayments::ensureNoneWaiting($locked);
            ReceiveCustomerPayment::ensurePayable($locked, $amount);

            return CustomerPayment::create([
                'number' => $this->numbers->next(DocumentNumber::CUSTOMER_PAYMENT),
                'customer_id' => $locked->id,
                'shift_id' => $shift->id,
                'user_id' => $actor->id,
                'amount' => $amount,
                'method' => CustomerPayment::QRIS,
                'status' => CustomerPaymentStatus::PendingPayment,
                'note' => ReceiveCustomerPayment::cleanNote($note),
            ]);
        });

        try {
            $charge = $this->midtrans->chargeQris($payment->number, $payment->amount);
        } catch (MidtransException $e) {
            $payment->forceFill(['status' => CustomerPaymentStatus::Cancelled])->save();

            throw ValidationException::withMessages(['payment' => $e->getMessage()]);
        }

        $payment->forceFill([
            'payment_reference' => $charge['transaction_id'],
            'qr_string' => $charge['qr_string'],
            'qr_url' => $charge['qr_url'],
            'payment_expires_at' => $charge['expires_at'] !== null
                ? CarbonImmutable::parse($charge['expires_at'], 'Asia/Jakarta') // Midtrans reports WIB
                : now()->addMinutes((int) config('services.midtrans.qris_expiry_minutes', 15)),
        ])->save();

        return $payment;
    }
}
