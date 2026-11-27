<?php

namespace App\Actions\Customers;

use App\Actions\Sales\ResolvesOpenShift;
use App\Enums\CustomerPaymentStatus;
use App\Enums\LedgerEntryType;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\User;
use App\Services\Documents\DocumentNumber;
use App\Services\Receivables\CustomerLedger;
use App\Support\Rupiah;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A customer pays off kasbon (pelunasan), part or all, in cash into the open shift of
 * whoever receives it. The amount goes to the oldest kasbon first (DESIGN.md §17.2).
 * Refused while a QRIS payment for the same customer waits (§18.2).
 */
final class ReceiveCustomerPayment
{
    use ResolvesOpenShift;

    public function __construct(
        private readonly CustomerLedger $ledger,
        private readonly DocumentNumber $numbers,
        private readonly QrisCustomerPayments $qris,
    ) {}

    public function handle(User $actor, Customer $customer, int $amount, ?string $note = null): CustomerPayment
    {
        self::ensurePositive($amount);

        $shift = $this->openShiftOf($actor);
        $this->qris->clearExpired($customer);

        return DB::transaction(function () use ($actor, $customer, $amount, $note, $shift) {
            $this->lockOpenShift($shift);
            $locked = $this->ledger->lock($customer);

            QrisCustomerPayments::ensureNoneWaiting($locked);
            self::ensurePayable($locked, $amount);

            $payment = CustomerPayment::create([
                'number' => $this->numbers->next(DocumentNumber::CUSTOMER_PAYMENT),
                'customer_id' => $locked->id,
                'shift_id' => $shift->id,
                'user_id' => $actor->id,
                'amount' => $amount,
                'method' => CustomerPayment::CASH,
                'status' => CustomerPaymentStatus::Completed,
                'completed_at' => now(),
                'note' => self::cleanNote($note),
            ]);

            $this->ledger->allocate($locked, LedgerEntryType::Payment, $amount, $payment, $actor);

            return $payment;
        });
    }

    public static function ensurePositive(int $amount): void
    {
        if ($amount < 1) {
            throw ValidationException::withMessages(['amount' => 'Jumlah pelunasan minimal Rp 1.']);
        }
    }

    /** The amount must fit what the (locked) customer owes: no deposit balance (§17.4). */
    public static function ensurePayable(Customer $locked, int $amount): void
    {
        if ($locked->balance === 0) {
            throw ValidationException::withMessages(['amount' => "{$locked->name} tidak punya utang."]);
        }
        if ($amount > $locked->balance) {
            throw ValidationException::withMessages([
                'amount' => 'Melebihi utang '.$locked->name.' ('.Rupiah::format($locked->balance).').',
            ]);
        }
    }

    public static function cleanNote(?string $note): ?string
    {
        return $note === null || trim($note) === '' ? null : trim($note);
    }
}
