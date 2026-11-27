<?php

namespace App\Actions\Customers;

use App\Actions\Sales\ResolvesOpenShift;
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
 */
final class ReceiveCustomerPayment
{
    use ResolvesOpenShift;

    public function __construct(
        private readonly CustomerLedger $ledger,
        private readonly DocumentNumber $numbers,
    ) {}

    public function handle(User $actor, Customer $customer, int $amount, ?string $note = null): CustomerPayment
    {
        if ($amount < 1) {
            throw ValidationException::withMessages(['amount' => 'Jumlah pelunasan minimal Rp 1.']);
        }

        $shift = $this->openShiftOf($actor);

        return DB::transaction(function () use ($actor, $customer, $amount, $note, $shift) {
            $this->lockOpenShift($shift);
            $locked = $this->ledger->lock($customer);

            if ($locked->balance === 0) {
                throw ValidationException::withMessages(['amount' => "{$locked->name} tidak punya utang."]);
            }
            if ($amount > $locked->balance) {
                throw ValidationException::withMessages([
                    'amount' => 'Melebihi utang '.$locked->name.' ('.Rupiah::format($locked->balance).').',
                ]);
            }

            $payment = CustomerPayment::create([
                'number' => $this->numbers->next(DocumentNumber::CUSTOMER_PAYMENT),
                'customer_id' => $locked->id,
                'shift_id' => $shift->id,
                'user_id' => $actor->id,
                'amount' => $amount,
                'method' => 'cash',
                'note' => $note === null || trim($note) === '' ? null : trim($note),
            ]);

            $this->ledger->allocate($locked, LedgerEntryType::Payment, $amount, $payment, $actor);

            return $payment;
        });
    }
}
