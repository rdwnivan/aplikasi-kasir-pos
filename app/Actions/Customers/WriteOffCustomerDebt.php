<?php

namespace App\Actions\Customers;

use App\Enums\LedgerEntryType;
use App\Models\Customer;
use App\Models\CustomerWriteOff;
use App\Models\Sale;
use App\Models\User;
use App\Services\Receivables\CustomerLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owner only: write off what is left of one kasbon sale, or of everything the customer
 * owes (oldest first). A bad-debt expense, so sales and gross profit stay as they are.
 */
final class WriteOffCustomerDebt
{
    use SavesCustomers;

    public function __construct(
        private readonly CustomerLedger $ledger,
        private readonly QrisCustomerPayments $qris,
    ) {}

    public function handle(User $owner, Customer $customer, string $reason, ?Sale $sale = null): CustomerWriteOff
    {
        $this->ensureOwner($owner);
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan wajib diisi.']);
        }

        $this->qris->clearExpired($customer);

        return DB::transaction(function () use ($owner, $customer, $reason, $sale) {
            $locked = $this->ledger->lock($customer);
            QrisCustomerPayments::ensureNoneWaiting($locked);

            if ($sale !== null && $sale->customer_id !== $locked->id) {
                throw ValidationException::withMessages(['sale' => 'Transaksi ini bukan kasbon pelanggan ini.']);
            }

            $amount = $sale === null ? $locked->balance : $this->ledger->owedOn($sale->id);
            if ($amount === 0) {
                throw ValidationException::withMessages(['sale' => 'Tidak ada sisa utang yang bisa dihapus.']);
            }

            $writeOff = CustomerWriteOff::create([
                'customer_id' => $locked->id,
                'user_id' => $owner->id,
                'amount' => $amount,
                'reason' => $reason,
            ]);

            $this->ledger->allocate($locked, LedgerEntryType::WriteOff, $amount, $writeOff, $owner, only: $sale, note: $reason);

            return $writeOff;
        });
    }
}
